<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ShippingMethod;
use App\Services\CheckoutPricingService;
use App\Services\ShippingService;
use App\Services\TaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CheckoutBoundarySafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['services.ikhokha.app_id' => 'SYNTHETIC', 'services.ikhokha.app_secret' => 'SYNTHETIC']);
        Http::fake(['*' => Http::response(['responseCode' => '00', 'paylinkID' => 'SYNTHETIC',
            'paylinkUrl' => 'https://securepay.ikhokha.red/synthetic'])]);
    }

    private function product(float $price = 0): Product
    {
        $category = ProductCategory::create(['name' => 'Flowers', 'slug' => 'flowers']);

        return Product::create(['name' => 'Flowers', 'slug' => 'flowers', 'category_id' => $category->id,
            'price' => $price, 'inventory_count' => 10, 'is_downloadable' => false]);
    }

    private function method(array $overrides = []): ShippingMethod
    {
        return ShippingMethod::create($overrides + ['name' => 'Local delivery', 'base_rate' => 0,
            'weight_rate' => 0, 'max_weight' => 1000, 'is_active' => true]);
    }

    private function address(ShippingMethod $method): array
    {
        return ['email' => 'synthetic@example.test', 'payment_method' => 'ikhokha',
            'shipping_method_id' => $method->id, 'shipping_address' => 'Synthetic street',
            'delivery_contact_name' => 'Synthetic recipient', 'delivery_phone' => '+27821234567',
            'shipping_country' => 'ZA', 'shipping_city' => 'Pretoria', 'shipping_postal_code' => '0001'];
    }

    private function assertNoCheckoutEffects(): void
    {
        foreach (['orders', 'order_items', 'invoices', 'stock_reservations', 'payment_transactions', 'delivery_bookings'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Http::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_quote_rejects_a_subtotal_exceeding_invoice_storage(): void
    {
        $product = $this->product(60000000);
        $this->withSession(['cart' => [$product->id => ['quantity' => 2]]])
            ->postJson(route('checkout.quote'), $this->address($this->method()))
            ->assertUnprocessable()->assertJsonValidationErrors('cart');
        $this->assertNoCheckoutEffects();
    }

    public function test_payment_submission_rejects_overflow_before_orders_holds_or_gateway_calls(): void
    {
        $product = $this->product(60000000);
        $this->withSession(['cart' => [$product->id => ['quantity' => 2]]])
            ->post(route('checkout.process'), $this->address($this->method()))
            ->assertSessionHasErrors('cart');
        $this->assertNoCheckoutEffects();
        $this->assertSame(10, $product->fresh()->inventory_count);
    }

    public function test_combined_total_cannot_overflow_when_components_fit_individually(): void
    {
        $product = $this->product(99999990);
        $this->withSession(['cart' => [$product->id => ['quantity' => 1]]])
            ->postJson(route('checkout.quote'), $this->address($this->method(['base_rate' => 10])))
            ->assertUnprocessable()->assertJsonValidationErrors('cart');
        $this->assertNoCheckoutEffects();
    }

    public function test_exact_storage_boundary_remains_quoteable(): void
    {
        $product = $this->product(99999999.99);
        $this->withSession(['cart' => [$product->id => ['quantity' => 1]]])
            ->postJson(route('checkout.quote'), $this->address($this->method()))
            ->assertOk()->assertJsonPath('total', 99999999.99);
        $this->assertNoCheckoutEffects();
    }

    public static function invalidPostcodes(): array
    {
        return [['ABCD'], ['123'], ['12345'], ['12 3'], [1234], [['0001']]];
    }

    #[DataProvider('invalidPostcodes')]
    public function test_quote_and_submission_reject_invalid_south_african_postcodes(mixed $code): void
    {
        $product = $this->product();
        $address = array_replace($this->address($this->method()), ['shipping_postal_code' => $code]);
        $this->withSession(['cart' => [$product->id => ['quantity' => 1]]]);
        $this->postJson(route('checkout.quote'), $address)->assertUnprocessable()->assertJsonValidationErrors('shipping_postal_code');
        $this->post(route('checkout.process'), $address)->assertSessionHasErrors('shipping_postal_code');
        $this->assertNoCheckoutEffects();
    }

    public function test_leading_zero_postcode_survives_quote_and_free_checkout(): void
    {
        $product = $this->product();
        $address = $this->address($this->method(['postal_codes' => ['0001']]));
        $this->withSession(['cart' => [$product->id => ['quantity' => 1]]]);
        $this->postJson(route('checkout.quote'), $address)->assertOk()->assertJsonPath('total', 0);
        $this->post(route('checkout.process'), $address)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('orders', ['shipping_postal_code' => '0001', 'payment_status' => 'paid']);
        Http::assertNothingSent();
    }

    public function test_mixed_cart_uses_only_physical_weight_for_delivery_price_and_limits(): void
    {
        $method = $this->method(['base_rate' => 10, 'weight_rate' => 5, 'max_weight' => 3]);
        $cart = [['price' => 20, 'quantity' => 1, 'weight' => 2, 'is_downloadable' => false],
            ['price' => 10, 'quantity' => 1, 'weight' => 100, 'is_downloadable' => true]];
        $service = app(ShippingService::class);
        $this->assertCount(1, $service->getAvailableShippingMethods($cart, 'Synthetic street', '0001'));
        $this->assertSame(20.0, $service->calculateShippingCost($method, $cart, 'Synthetic street'));
        $totals = app(CheckoutPricingService::class)->calculate($cart, $this->address($method));
        $this->assertSame(30.0, $totals['subtotal']);
        $this->assertSame(50.0, $totals['total']);
    }

    public static function invalidTaxAmounts(): array
    {
        return [[-1.0], [INF], [NAN], [100000000.0]];
    }

    #[DataProvider('invalidTaxAmounts')]
    public function test_invalid_calculated_tax_is_rejected_before_payment(float $tax): void
    {
        $this->mock(TaxService::class)->shouldReceive('calculateTax')->once()->andReturn($tax);
        $cart = [['price' => 10, 'quantity' => 1, 'weight' => 0, 'is_downloadable' => false]];
        $address = $this->address($this->method());
        $this->expectException(ValidationException::class);
        app(CheckoutPricingService::class)->calculate($cart, $address);
    }

    public function test_weight_based_shipping_cannot_overflow_order_storage(): void
    {
        $method = $this->method(['weight_rate' => 999999.99]);
        $cart = [['price' => 10, 'quantity' => 1, 'weight' => 200, 'is_downloadable' => false]];
        $this->expectException(ValidationException::class);
        app(CheckoutPricingService::class)->calculate($cart, $this->address($method));
    }

    public static function invalidWeights(): array
    {
        return [[-1], [INF], [NAN], ['invalid']];
    }

    #[DataProvider('invalidWeights')]
    public function test_invalid_physical_weight_is_not_silently_priced(mixed $weight): void
    {
        $method = $this->method(['base_rate' => 50, 'weight_rate' => 2]);
        $cart = [['price' => 10, 'quantity' => 1, 'weight' => $weight, 'is_downloadable' => false]];
        $this->expectException(ValidationException::class);
        app(ShippingService::class)->calculateShippingCost($method, $cart, 'Synthetic street');
    }

    public function test_checkout_postal_field_explains_the_required_format(): void
    {
        $product = $this->product();
        $this->withSession(['cart' => [$product->id => ['quantity' => 1]]])->get(route('checkout.initiate'))
            ->assertOk()->assertSee('Enter four digits, including any leading zeros.')
            ->assertSee('pattern="[0-9]{4}"', false)->assertSee('inputmode="numeric"', false);
    }
}
