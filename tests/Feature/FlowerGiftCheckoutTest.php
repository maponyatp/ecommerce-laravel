<?php

namespace Tests\Feature;

use App\Jobs\DispatchDropshippingOrder;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ShippingMethod;
use App\Notifications\OrderConfirmationNotification;
use App\Services\CheckoutPricingService;
use App\Services\FulfillmentDocumentService;
use App\Services\OrderPaymentService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FlowerGiftCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();
        config(['services.ikhokha.app_id' => 'SYNTHETIC', 'services.ikhokha.app_secret' => 'SYNTHETIC',
            'shipping.drop_shipping_premium' => 250]);
        Http::fake(['*' => Http::response(['responseCode' => '00', 'paylinkID' => 'SYNTHETIC',
            'paylinkUrl' => 'https://securepay.ikhokha.red/synthetic'])]);
    }

    private function basket(float $price = 0, float $shipping = 0): array
    {
        $category = ProductCategory::create(['name' => 'Flowers', 'slug' => 'flowers']);
        $product = Product::create(['name' => 'Gift bouquet', 'slug' => 'gift-bouquet', 'category_id' => $category->id,
            'price' => $price, 'inventory_count' => 5, 'is_downloadable' => false]);
        $method = ShippingMethod::create(['name' => 'Local flowers', 'base_rate' => $shipping,
            'weight_rate' => 0, 'max_weight' => 10, 'is_active' => true]);
        $this->withSession(['cart' => [$product->id => ['quantity' => 1]]]);

        return ['email' => 'buyer@example.test', 'shipping_method_id' => $method->id,
            'delivery_contact_name' => 'Flower recipient', 'delivery_phone' => '+27821234567',
            'shipping_address' => 'Synthetic recipient address', 'shipping_city' => 'Pretoria',
            'shipping_country' => 'ZA', 'shipping_postal_code' => '0001',
            'gift_message' => 'Happy birthday! With love from the buyer.'];
    }

    public function test_checkout_shows_optional_gift_note_without_a_supplier_selector_or_dropshipping_toggle(): void
    {
        $this->basket();
        $this->get(route('checkout.initiate'))->assertOk()
            ->assertSee('Gift message')->assertSee('No extra gifting fee')
            ->assertSee('name="gift_message"', false)->assertSee('maxlength="2000"', false)
            ->assertDontSee('name="dropship"', false)->assertDontSee('name="supplier_id"', false)
            ->assertDontSee('name="recipient_email"', false)->assertDontSee('Select Supplier');
        Http::assertNothingSent();
    }

    public function test_gift_checkout_uses_normal_delivery_and_keeps_the_buyer_receipt_separate(): void
    {
        $address = $this->basket();
        $this->postJson(route('checkout.quote'), $address)->assertOk()->assertJsonPath('shipping', 0)->assertJsonPath('total', 0);
        $this->post(route('checkout.process'), $address)->assertRedirect()->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $this->assertSame('Flower recipient', $order->recipient_name);
        $this->assertSame('buyer@example.test', $order->customer_email);
        $this->assertNull($order->recipient_email);
        $this->assertSame($address['gift_message'], $order->gift_message);
        $this->assertFalse($order->is_dropshipped);
        $this->assertNull($order->supplier_id);
        $this->assertSame('processing', $order->status);
        $this->assertTrue(app(FulfillmentDocumentService::class)->canPrint($order));
        Notification::assertSentOnDemand(OrderConfirmationNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'buyer@example.test');
        Queue::assertNotPushed(DispatchDropshippingOrder::class);
        Http::assertNothingSent();
    }

    public function test_paid_gift_uses_the_quoted_amount_and_normal_fulfilment_after_verified_settlement(): void
    {
        $address = $this->basket(100, 20);
        $this->postJson(route('checkout.quote'), $address)->assertOk()->assertJsonPath('shipping', 20)->assertJsonPath('total', 120);
        $this->post(route('checkout.process'), $address + ['payment_method' => 'ikhokha'])
            ->assertSessionHasNoErrors()->assertRedirect('https://securepay.ikhokha.red/synthetic');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.ikhokha.com/public-api/v1/api/payment' && $request['amount'] === 12000);
        $order = Order::firstOrFail();
        // Synthetic settlement through the existing trusted service, not a real provider payment.
        $order = app(OrderPaymentService::class)->markPaid($order->paymentTransactions()->firstOrFail());
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('processing', $order->status);
        $this->assertSame('Flower recipient', $order->recipient_name);
        $this->assertFalse($order->is_dropshipped);
        $this->assertTrue(app(FulfillmentDocumentService::class)->canPrint($order));
        $this->assertSame(120.0, (float) $order->invoice->total_amount);
        Queue::assertNotPushed(DispatchDropshippingOrder::class);
    }

    public static function supplierRequests(): array
    {
        return [
            'checkbox' => [['dropship' => 'on']],
            'numeric flag' => [['dropship' => 1]],
            'string flag' => [['dropship' => '1']],
            'boolean flag' => [['dropship' => true]],
            'array flag' => [['dropship' => ['on']]],
            'placeholder supplier' => [['supplier_id' => 'supplier1']],
            'named supplier' => [['supplier_id' => 'dropxl']],
            'array supplier' => [['supplier_id' => ['dropxl']]],
        ];
    }

    #[DataProvider('supplierRequests')]
    public function test_public_checkout_rejects_supplier_ordering_before_side_effects(array $options): void
    {
        $address = $this->basket(100, 20) + ['recipient_name' => 'Recipient', 'recipient_email' => 'recipient@example.test'];
        $address = array_replace($address, $options);
        $field = array_key_first($options);
        $this->postJson(route('checkout.quote'), $address)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->post(route('checkout.process'), $address + ['payment_method' => 'ikhokha'])->assertSessionHasErrors($field);
        foreach (['orders', 'order_items', 'stock_reservations', 'payment_transactions', 'invoices'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Queue::assertNotPushed(DispatchDropshippingOrder::class);
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_disabled_legacy_flag_can_still_use_normal_delivery(): void
    {
        $address = $this->basket() + ['dropship' => '0'];
        $this->postJson(route('checkout.quote'), $address)->assertOk();
        $this->post(route('checkout.process'), $address)->assertSessionHasNoErrors();
        $this->assertFalse(Order::firstOrFail()->is_dropshipped);
        Queue::assertNotPushed(DispatchDropshippingOrder::class);
        Http::assertNothingSent();
    }

    public function test_pricing_service_cannot_be_used_to_bypass_the_supplier_ordering_gate(): void
    {
        $address = $this->basket();
        $cart = [['price' => 0, 'quantity' => 1, 'weight' => 0, 'is_downloadable' => false]];
        $this->expectException(ValidationException::class);
        app(CheckoutPricingService::class)->calculate($cart, $address, dropship: true);
    }

    public function test_gift_note_length_and_type_are_validated_without_creating_an_order(): void
    {
        $address = $this->basket();
        foreach ([str_repeat('x', 2001), ['unexpected']] as $note) {
            $this->post(route('checkout.process'), array_replace($address, ['gift_message' => $note]))
                ->assertSessionHasErrors('gift_message');
        }
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_digital_checkout_exposes_required_billing_fields_without_flower_delivery_fields(): void
    {
        $this->basket();
        Storage::fake('local');
        Storage::disk('local')->put('downloadable_products/synthetic.txt', 'Synthetic download');
        Product::firstOrFail()->update(['is_downloadable' => true, 'downloadable_file' => 'downloadable_products/synthetic.txt']);
        $settings = app(GeneralSettings::class);
        $settings->invoice_vat_status = 'registered';
        $settings->invoice_tax_number = '4123456789';
        $settings->save();
        $this->get(route('checkout.initiate'))->assertOk()
            ->assertSee('name="billing_name"', false)->assertSee('name="billing_address"', false)
            ->assertDontSee('name="delivery_contact_name"', false)->assertDontSee('name="gift_message"', false);
        $this->post(route('checkout.process'), ['email' => 'digital-buyer@example.test',
            'billing_name' => 'Digital buyer', 'billing_address' => 'Synthetic billing address'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $order = Order::firstOrFail();
        $this->assertSame('Digital buyer', $order->billing_details['name']);
        $this->assertSame('not_required', $order->shipping_status);
        Http::assertNothingSent();
    }
}
