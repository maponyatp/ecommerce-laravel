<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ShippingMethod;
use App\Models\TaxClass;
use App\Models\TaxRate;
use App\Services\PaymentGateways\StripeGateway;
use App\Services\Payments\IkhokhaGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Tests\TestCase;

class InternationalStorefrontTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        $category = ProductCategory::create(['name' => 'Bouquets', 'slug' => 'bouquets']);

        return Product::create(['category_id' => $category->id, 'name' => 'Rose bouquet', 'slug' => 'rose-bouquet',
            'price' => 100, 'inventory_count' => 30, 'featured_image' => 'products/roses.jpg',
            'meta_title' => 'Fresh rose bouquet', 'description' => 'Beautiful flowers', 'is_downloadable' => false]);
    }

    private function address(): array
    {
        $method = ShippingMethod::create(['name' => 'Delivery', 'description' => 'Test delivery', 'base_rate' => 15,
            'weight_rate' => 0, 'max_weight' => 20]);

        return ['email' => 'overseas-buyer@example.test', 'shipping_method_id' => $method->id,
            'delivery_contact_name' => 'Flower Recipient', 'delivery_phone' => '+27821234567',
            'shipping_address' => '1 Flower Street', 'shipping_country' => 'ZA',
            'shipping_city' => 'Johannesburg', 'shipping_region' => 'GP', 'shipping_postal_code' => '2001'];
    }

    private function gateway(): void
    {
        config(['services.ikhokha.app_id' => 'test-app', 'services.ikhokha.app_secret' => 'test-secret']);
        Http::preventStrayRequests();
        Http::fake(['https://api.ikhokha.com/*' => Http::response([
            'responseCode' => '00', 'paylinkID' => 'market-link', 'paylinkUrl' => 'https://securepay.ikhokha.red/market',
        ])]);
    }

    public function test_quote_and_payment_agree_on_currency_discount_shipping_and_configured_destination_tax(): void
    {
        $product = $this->product();
        $address = $this->address();
        $class = TaxClass::create(['name' => 'Test tax', 'slug' => 'test-tax']);
        TaxRate::create(['tax_class_id' => $class->id, 'name' => 'Test rate', 'country' => 'ZA', 'rate' => 7, 'is_active' => true]);
        TaxRate::create(['tax_class_id' => $class->id, 'name' => 'Wrong country rate', 'country' => 'US', 'rate' => 90, 'is_active' => true]);
        Coupon::create(['code' => 'TEN', 'type' => 'percentage', 'value' => 10, 'valid_from' => now()->subDay(), 'valid_until' => now()->addDay()]);
        $this->withSession(['cart' => [$product->id => ['quantity' => 1]], 'coupon' => ['code' => 'TEN', 'discount' => 999]])
            ->postJson(route('checkout.quote'), $address)->assertOk()->assertJson([
                'currency' => 'ZAR', 'subtotal' => 100, 'discount' => 10, 'shipping' => 15, 'tax' => 6.3, 'total' => 111.3,
            ]);
        $this->assertSame(0, Order::count());
        $this->gateway();
        $this->post(route('checkout.process'), $address + ['payment_method' => 'ikhokha', 'currency' => 'USD', 'total' => 1])
            ->assertRedirect('https://securepay.ikhokha.red/market')->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $this->assertSame('ZAR', $order->currency);
        $this->assertSame('111.30', $order->total_amount);
        $this->assertSame('ZA', $order->shipping_country);
        $this->assertSame('Flower Recipient', $order->delivery_contact_name);
        $this->assertStringContainsString('South Africa', $order->shipping_address);
        Http::assertSent(fn ($request) => $request['currency'] === 'ZAR' && $request['amount'] === 11130);
    }

    public function test_unsupported_delivery_destination_is_rejected_before_order_or_payment(): void
    {
        $this->gateway();
        $product = $this->product();
        $address = $this->address();
        $address['shipping_country'] = 'US';
        $this->withSession(['cart' => [$product->id => ['quantity' => 1]]])
            ->postJson(route('checkout.quote'), $address)->assertUnprocessable()->assertJsonValidationErrors('shipping_country');
        $this->post(route('checkout.process'), $address + ['payment_method' => 'ikhokha'])->assertSessionHasErrors('shipping_country');
        $this->assertSame(0, Order::count());
        Http::assertNothingSent();
    }

    public function test_delivery_contact_and_structured_address_are_required_for_physical_goods(): void
    {
        $product = $this->product();
        $this->withSession(['cart' => [$product->id => ['quantity' => 1]]])
            ->postJson(route('checkout.quote'), [])->assertUnprocessable()
            ->assertJsonValidationErrors(['delivery_contact_name', 'delivery_phone', 'shipping_country', 'shipping_city', 'shipping_postal_code']);
    }

    public function test_price_change_after_quote_requires_review_before_payment(): void
    {
        $product = $this->product();
        $address = $this->address();
        $this->gateway();
        $this->withSession(['cart' => [$product->id => ['quantity' => 1]]])
            ->postJson(route('checkout.quote'), $address)->assertOk();
        $product->update(['price' => 200]);
        $this->post(route('checkout.process'), $address + ['payment_method' => 'ikhokha'])->assertSessionHasErrors('quote');
        Http::assertNothingSent();
        $this->assertSame(0, Order::count());
    }

    public function test_catalogue_checkout_and_cart_display_unambiguous_zar_prices(): void
    {
        $product = $this->product();
        $this->address();
        $this->get('/products')->assertOk()->assertSee('ZAR 100.00');
        $this->withSession(['cart' => [$product->id => ['quantity' => 1, 'name' => $product->name, 'price' => 100, 'is_downloadable' => false]]])
            ->get('/cart')->assertOk()->assertSee('ZAR 100.00');
        $this->get('/checkout')->assertOk()->assertSee('Prices and payments are in ZAR')->assertSee('Delivery country');
    }

    public function test_product_metadata_matches_price_image_and_name_and_does_not_expose_inventory_logs(): void
    {
        $product = $this->product();
        $response = $this->get(route('products.show', $product))->assertOk()->assertSee('Fresh rose bouquet')
            ->assertSee('/storage/products/roses.jpg')->assertDontSee('Inventory Logs');
        preg_match('/<script type="application\/ld\+json">\s*(.*?)\s*<\/script>/s', $response->getContent(), $match);
        $schema = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ZAR', $schema['offers']['priceCurrency']);
        $this->assertSame('100.00', $schema['offers']['price']);
        $this->assertStringEndsWith('/storage/products/roses.jpg', $schema['image']);
        $this->assertArrayNotHasKey('mpn', $schema);
    }

    public function test_jsonld_cannot_be_broken_out_of_by_product_content(): void
    {
        $product = $this->product();
        $product->update(['description' => '</script><script>alert("x")</script>']);
        $this->get(route('products.show', $product))->assertOk()->assertDontSee('</script><script>alert("x")</script>', false);
    }

    public function test_historical_invoice_uses_recorded_currency_and_marks_unknown_currency(): void
    {
        $order = Order::create(['customer_email' => 'buyer@example.test', 'order_date' => now(), 'total_amount' => 100,
            'payment_status' => 'paid', 'payment_method' => 'stripe', 'shipping_status' => 'not_required', 'status' => 'paid', 'currency' => 'USD']);
        $invoice = Invoice::create(['order_id' => $order->id, 'invoice_date' => now(), 'total_amount' => 100, 'payment_status' => 'paid']);
        $url = URL::temporarySignedRoute('invoices.print', now()->addHour(), ['invoice' => $invoice]);
        $this->get($url)->assertOk()->assertSee('Currency: USD')->assertSee('USD 100.00')->assertDontSee('Currency: ZAR');
        $order->update(['currency' => null]);
        $this->get($url)->assertOk()->assertSee('currency not recorded')->assertDontSee('ZAR 100.00');
    }

    public function test_ikhokha_rejects_an_order_in_another_currency(): void
    {
        $this->gateway();
        try {
            app(IkhokhaGateway::class)->createPaymentLink(new Order(['currency' => 'USD', 'total_amount' => 100]), 'wrong-currency');
            $this->fail('A USD order must not be relabelled as ZAR.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('ZAR', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_stripe_uses_order_currency_and_integer_minor_units(): void
    {
        config(['services.stripe.secret' => 'sk_test_fake']);
        $client = \Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')->once()->withArgs(function ($method, $url, $headers, $params) {
            return $params['currency'] === 'zar' && $params['amount'] === 12345;
        })->andReturn(['{"id":"ch_test","object":"charge","status":"succeeded"}', 200, []]);
        ApiRequestor::setHttpClient($client);
        try {
            $result = app(StripeGateway::class)->processPayment(123.45, ['token' => 'tok_test', 'currency' => 'ZAR']);
            $this->assertTrue($result['success']);
        } finally {
            ApiRequestor::setHttpClient(new CurlClient);
        }
    }

    public function test_checkout_only_links_published_real_policy_pages(): void
    {
        $product = $this->product();
        Page::create(['title' => 'Delivery policy', 'slug' => 'delivery-policy', 'content' => 'Test policy', 'status' => 'published']);
        Page::create(['title' => 'Private draft policy', 'slug' => 'privacy-policy', 'content' => 'Draft', 'status' => 'draft']);
        $this->withSession(['cart' => [$product->id => ['quantity' => 1]]])->get('/checkout')
            ->assertOk()->assertSee(route('cms.pages.show', 'delivery-policy'))->assertDontSee('Private draft policy')
            ->assertDontSee('Buyer Protection');
    }
}
