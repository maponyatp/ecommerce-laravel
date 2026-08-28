<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ShippingMethod;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class CheckoutControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(array $overrides = []): Product
    {
        $category = ProductCategory::create([
            'name' => 'Checkout Category',
            'slug' => 'checkout-cat-'.uniqid(),
        ]);

        return Product::create(array_merge([
            'name' => 'Checkout Product',
            'slug' => 'checkout-prod-'.uniqid(),
            'price' => 50.00,
            'category_id' => $category->id,
            'inventory_count' => 20,
            'is_downloadable' => false,
        ], $overrides));
    }

    private function makeShippingMethod(array $overrides = []): ShippingMethod
    {
        return ShippingMethod::create(array_merge([
            'name' => 'Standard Shipping',
            'description' => 'Standard delivery',
            'base_rate' => 5.00,
            'weight_rate' => 0.00,
            'max_weight' => 100.0,
        ], $overrides));
    }

    public function test_initiate_checkout_redirects_with_empty_cart(): void
    {
        $response = $this->get(route('checkout.initiate'));

        $response->assertRedirect(route('products.index'));
        $response->assertSessionHas('error');
    }

    public function test_vat_vendor_checkout_requires_separate_buyer_details_before_creating_order(): void
    {
        $settings = app(GeneralSettings::class);
        $settings->invoice_vat_status = 'registered';
        $settings->invoice_tax_number = '4123456789';
        $settings->save();
        Storage::fake('local');
        Storage::disk('local')->put('downloadable_products/test.txt', 'Synthetic download');
        $product = $this->makeProduct(['price' => 0, 'is_downloadable' => true, 'downloadable_file' => 'downloadable_products/test.txt']);
        $this->withSession(['cart' => [$product->id => ['quantity' => 1]]]);
        $this->post(route('checkout.process'), ['email' => 'buyer@example.test'])
            ->assertSessionHasErrors(['billing_name', 'billing_address']);
        $this->assertDatabaseCount('orders', 0);
        $this->post(route('checkout.process'), ['email' => 'buyer@example.test', 'billing_name' => 'Buyer', 'billing_address' => '20 Buyer Street, Cape Town, South Africa', 'billing_is_vat_vendor' => '1'])
            ->assertSessionHasErrors(['billing_vat_number']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_saves_billing_details_and_tax_context_without_using_delivery_recipient(): void
    {
        Notification::fake();
        $settings = app(GeneralSettings::class);
        $settings->invoice_vat_status = 'not_registered';
        $settings->save();
        Storage::fake('local');
        Storage::disk('local')->put('downloadable_products/test.txt', 'Synthetic download');
        $product = $this->makeProduct(['price' => 0, 'is_downloadable' => true, 'downloadable_file' => 'downloadable_products/test.txt']);
        $this->withSession(['cart' => [$product->id => ['quantity' => 1]]]);
        $this->post(route('checkout.process'), ['email' => 'buyer@example.test', 'billing_name' => 'Buyer Company', 'billing_address' => '20 Buyer Street, Cape Town, South Africa', 'billing_vat_number' => '4123456789'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $order = Order::firstOrFail();
        $this->assertSame('Buyer Company', $order->billing_details['name']);
        $this->assertTrue($order->billing_details['is_vat_vendor']);
        $this->assertSame('not_registered', $order->invoice_context['vat_status']);
        $this->assertSame($order->billing_details, $order->invoice->document_snapshot['buyer']);
    }

    public function test_initiate_checkout_returns_view_with_cart(): void
    {
        $product = $this->makeProduct();
        $this->withSession(['cart' => [
            $product->id => [
                'name' => $product->name,
                'price' => $product->price,
                'quantity' => 1,
                'is_downloadable' => false,
            ],
        ]]);

        $response = $this->get(route('checkout.initiate'));

        $response->assertStatus(200);
        $response->assertViewHas('cart');
    }

    public function test_process_checkout_validation_requires_email(): void
    {
        $product = $this->makeProduct();
        $this->withSession(['cart' => [
            $product->id => [
                'name' => $product->name,
                'price' => $product->price,
                'quantity' => 1,
                'is_downloadable' => false,
            ],
        ]]);

        $response = $this->post(route('checkout.process'), []);

        $response->assertSessionHasErrors('email');
    }

    public function test_process_checkout_with_empty_cart_redirects(): void
    {
        $response = $this->post(route('checkout.process'), [
            'email' => 'test@example.com',
            'payment_method' => 'cod',
        ]);

        $response->assertRedirect(route('products.index'));
        $response->assertSessionHas('error');
    }

    public function test_guest_checkout_stores_session_data(): void
    {
        $product = $this->makeProduct();
        $this->withSession(['cart' => [
            $product->id => [
                'name' => $product->name,
                'price' => 50.00,
                'quantity' => 1,
                'is_downloadable' => false,
            ],
        ]]);

        $response = $this->post(route('checkout.initiate'));

        $this->assertNotNull(session('cart'));
    }

    public function test_show_confirmation_returns_view(): void
    {
        $customer = Customer::create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'phone_number' => '555-0000',
            'address' => '1 Test St',
            'city' => 'Testville',
            'state' => 'CA',
            'postal_code' => '90001',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'customer_email' => 'test@example.com',
            'order_date' => now()->toDateString(),
            'total_amount' => 55.00,
            'payment_status' => 'paid',
            'shipping_status' => 'pending',
            'status' => 'paid',
        ]);

        $response = $this->get(URL::temporarySignedRoute(
            'checkout.confirmation',
            now()->addHour(),
            ['order' => $order->id],
        ));

        $response->assertStatus(200);
        $response->assertViewHas('order');
    }

    public function test_show_confirmation_rejects_an_unsigned_order_url(): void
    {
        $customer = Customer::create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'unsigned@example.com',
            'phone_number' => '555-0001',
            'address' => '1 Test St',
            'city' => 'Testville',
            'state' => 'CA',
            'postal_code' => '90001',
        ]);
        $order = Order::create([
            'customer_id' => $customer->id,
            'customer_email' => $customer->email,
            'order_date' => now()->toDateString(),
            'total_amount' => 10,
            'payment_status' => 'paid',
            'shipping_status' => 'pending',
            'status' => 'paid',
        ]);

        $this->get(route('checkout.confirmation', ['order' => $order->id]))
            ->assertForbidden();
    }
}
