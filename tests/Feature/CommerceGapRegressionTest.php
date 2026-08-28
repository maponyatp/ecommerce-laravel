<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\CouponService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CommerceGapRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function product(bool $digital = false, float $price = 50): Product
    {
        if ($digital) {
            Storage::fake('local');
            Storage::disk('local')->put('downloadable_products/regression.pdf', 'test document');
        }
        $category = ProductCategory::create(['name' => 'Regression', 'slug' => 'regression-'.uniqid()]);

        return Product::create([
            'category_id' => $category->id, 'name' => 'Regression item', 'slug' => 'item-'.uniqid(),
            'price' => $price, 'inventory_count' => 20, 'is_downloadable' => $digital,
            'downloadable_file' => $digital ? 'downloadable_products/regression.pdf' : null,
        ]);
    }

    private function cart(Product $product, int $quantity = 1): array
    {
        return [$product->id => ['name' => $product->name, 'price' => $product->price,
            'is_downloadable' => $product->is_downloadable, 'quantity' => $quantity]];
    }

    private function order(): Order
    {
        return Order::create([
            'customer_email' => 'buyer@example.test', 'order_date' => now()->toDateString(),
            'total_amount' => 100, 'payment_status' => 'pending', 'shipping_status' => 'unfulfilled',
            'payment_method' => 'ikhokha', 'status' => 'awaiting_payment',
        ]);
    }

    private function configureGateway(): void
    {
        config(['services.ikhokha.app_id' => 'test-app', 'services.ikhokha.app_secret' => 'test-secret']);
        Http::preventStrayRequests();
        Http::fake(['https://api.ikhokha.com/*' => Http::response([
            'responseCode' => '00', 'paylinkID' => 'link-1', 'paylinkUrl' => 'https://securepay.ikhokha.red/link-1',
        ])]);
    }

    public function test_unsigned_payment_return_cannot_mint_private_order_access(): void
    {
        $order = $this->order();
        $this->get(route('payments.ikhokha.return', $order))->assertForbidden();
        $this->get(URL::temporarySignedRoute('payments.ikhokha.return', now()->subMinute(), ['order' => $order]))->assertForbidden();
        $valid = URL::temporarySignedRoute('payments.ikhokha.return', now()->addHour(), ['order' => $order]);
        $this->get($valid.'&result=cancelled')->assertForbidden();
    }

    public function test_signed_return_never_marks_a_pending_order_paid(): void
    {
        $order = $this->order();
        $response = $this->get(URL::temporarySignedRoute('payments.ikhokha.return', now()->addHour(), ['order' => $order]));
        $response->assertRedirect();
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame(0, Invoice::count());
    }

    public function test_paid_confirmation_preserves_a_new_or_changed_cart(): void
    {
        $order = $this->order();
        $order->update(['payment_status' => 'paid']);
        $cart = $this->cart($this->product());
        $this->withSession(['cart' => $cart, 'pending_checkout' => [
            'order_id' => $order->id, 'cart_hash' => hash('sha256', serialize([])),
        ]])->get(URL::temporarySignedRoute('checkout.confirmation', now()->addHour(), ['order' => $order]))
            ->assertOk()->assertSessionHas('cart', $cart);
    }

    public function test_paid_confirmation_clears_only_its_unchanged_checkout_cart(): void
    {
        $order = $this->order();
        $order->update(['payment_status' => 'paid']);
        $cart = $this->cart($this->product());
        $this->withSession(['cart' => $cart, 'pending_checkout' => [
            'order_id' => $order->id, 'cart_hash' => hash('sha256', serialize($cart)),
        ]])->get(URL::temporarySignedRoute('checkout.confirmation', now()->addHour(), ['order' => $order]))
            ->assertOk()->assertSessionMissing('cart')->assertSessionMissing('pending_checkout');
    }

    public function test_physical_goods_cannot_bypass_shipping_by_changing_a_hidden_field(): void
    {
        $this->withSession(['cart' => $this->cart($this->product())])->post(route('checkout.process'), [
            'email' => 'buyer@example.test', 'has_physical_products' => 0,
        ])->assertSessionHasErrors(['shipping_address', 'shipping_method_id']);
        $this->assertSame(0, Order::count());
    }

    public function test_invalid_quantity_is_rejected_before_creating_an_order(): void
    {
        $this->withSession(['cart' => $this->cart($this->product(true), 0)])->post(route('checkout.process'), [
            'email' => 'buyer@example.test',
        ])->assertSessionHasErrors('cart');
        $this->assertSame(0, Order::count());
    }

    public function test_missing_payment_method_does_not_create_a_pending_order(): void
    {
        $this->configureGateway();
        $this->withSession(['cart' => $this->cart($this->product(true))])->post(route('checkout.process'), [
            'email' => 'buyer@example.test',
        ])->assertSessionHasErrors('payment_method');
        $this->assertSame(0, Order::count());
        Http::assertNothingSent();
    }

    public function test_expired_coupon_is_removed_and_checkout_stops_for_review(): void
    {
        Coupon::create(['code' => 'EXPIRED', 'type' => 'fixed', 'value' => 10,
            'valid_from' => now()->subDays(3), 'valid_until' => now()->subDay()]);
        $this->withSession(['cart' => $this->cart($this->product(true)), 'coupon' => ['code' => 'EXPIRED', 'discount' => 1000]])
            ->post(route('checkout.process'), ['email' => 'buyer@example.test'])
            ->assertSessionHasErrors('coupon')->assertSessionMissing('coupon');
        $this->assertSame(0, Order::count());
    }

    public function test_coupon_and_price_are_recalculated_before_hosted_payment(): void
    {
        $this->configureGateway();
        $product = $this->product(true);
        Coupon::create(['code' => 'TEN', 'type' => 'percentage', 'value' => 10, 'max_uses' => 3,
            'valid_from' => now()->subDay(), 'valid_until' => now()->addDay()]);
        $cart = $this->cart($product);
        $cart[$product->id]['price'] = 1;
        $response = $this->withSession(['cart' => $cart, 'coupon' => ['code' => 'TEN', 'discount' => 1000]])
            ->post(route('checkout.process'), ['email' => 'buyer@example.test', 'payment_method' => 'ikhokha']);
        $response->assertRedirect('https://securepay.ikhokha.red/link-1');
        $this->assertSame('45.00', Order::first()->total_amount);
        $this->assertSame('5.00', Order::first()->discount_amount);
        Http::assertSent(fn ($request) => $request['amount'] === 4500);
    }

    public function test_free_checkout_creates_invoice_and_sends_confirmation_without_schema_or_relationship_errors(): void
    {
        Notification::fake();
        $product = $this->product(true, 0);
        $this->withSession(['cart' => $this->cart($product)])->post(route('checkout.process'), [
            'email' => 'buyer@example.test',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('paid', Order::first()->payment_status);
        $this->assertSame(1, Invoice::count());
        $this->assertSame(19, $product->fresh()->inventory_count);
        Notification::assertCount(1);
    }

    public function test_coupon_usage_limit_counts_paid_orders_not_failed_attempts(): void
    {
        $coupon = Coupon::create(['code' => 'ONCE', 'type' => 'fixed', 'value' => 5, 'max_uses' => 1,
            'valid_from' => now()->subDay(), 'valid_until' => now()->addDay()]);
        $order = $this->order();
        $order->update(['coupon_code' => $coupon->code, 'payment_status' => 'failed']);
        $this->assertTrue($coupon->isValid());
        $order->update(['payment_status' => 'paid']);
        $this->assertFalse($coupon->isValid());
    }

    public function test_coupon_discount_cannot_exceed_the_cart_value(): void
    {
        $coupon = new Coupon(['type' => 'percentage', 'value' => 200]);
        $this->assertSame(50.0, app(CouponService::class)->calculateDiscount($coupon, 50));
    }

    public function test_invoice_access_rejects_another_customer_and_unsigned_print_link(): void
    {
        $order = $this->order();
        $invoice = Invoice::create(['order_id' => $order->id, 'invoice_date' => now(), 'total_amount' => 100, 'payment_status' => 'paid']);
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user)->get(route('invoices.show', $invoice->id))->assertForbidden();
        $this->get(route('invoices.print', $invoice))->assertForbidden();
        $this->get(URL::temporarySignedRoute('invoices.print', now()->addHour(), ['invoice' => $invoice]))->assertOk();
    }

    public function test_admin_invoice_table_renders_the_print_action_for_a_record(): void
    {
        $order = $this->order();
        Invoice::create(['order_id' => $order->id, 'invoice_number' => 'INV-TEST',
            'invoice_date' => now(), 'total_amount' => 100, 'payment_status' => 'paid']);
        $user = User::factory()->withPersonalTeam()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->current_team_id);
        $role = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::findOrCreate('view_any_invoice', 'web'));
        $user->assignRole($role);
        $this->actingAs($user)->get('/admin/invoices')->assertOk()->assertSee('View / Print');
    }

    public function test_shipping_method_must_support_the_cart_weight(): void
    {
        $product = $this->product();
        // A method with an invalid negative capacity must never be accepted.
        $method = ShippingMethod::create(['name' => 'Unavailable', 'description' => 'Unavailable',
            'base_rate' => 5, 'weight_rate' => 0, 'max_weight' => -1]);
        $this->withSession(['cart' => $this->cart($product)])->post(route('checkout.process'), [
            'email' => 'buyer@example.test', 'shipping_address' => '1 Main Street', 'shipping_method_id' => $method->id,
            'delivery_contact_name' => 'Recipient', 'delivery_phone' => '+27821234567',
            'shipping_country' => 'ZA', 'shipping_city' => 'Johannesburg', 'shipping_postal_code' => '2001',
        ])->assertSessionHasErrors('shipping_method_id');
        $this->assertSame(0, Order::count());
    }

    public function test_callbacks_keep_working_when_store_is_closed_and_are_idempotent(): void
    {
        $this->configureGateway();
        Notification::fake();
        $settings = app(GeneralSettings::class);
        $settings->storefront_enabled = false;
        $settings->save();
        $order = $this->order();
        $product = $this->product();
        $order->items()->create(['product_id' => $product->id, 'quantity' => 2, 'price' => 50]);
        PaymentTransaction::create(['order_id' => $order->id, 'gateway' => 'ikhokha',
            'external_transaction_id' => 'tx-1', 'gateway_reference' => 'link-1', 'amount' => 100, 'currency' => 'ZAR']);
        $payload = ['paylinkID' => 'link-1', 'status' => 'SUCCESS', 'externalTransactionID' => 'tx-1', 'responseCode' => '00'];
        $signature = hash_hmac('sha256', str_replace('"', '\\"', '/payments/ikhokha/webhook'.json_encode($payload)), 'test-secret');
        $this->get('/')->assertStatus(503);
        $this->postJson(route('payments.ikhokha.webhook'), $payload)->assertForbidden();
        for ($i = 0; $i < 2; $i++) {
            $this->postJson(route('payments.ikhokha.webhook'), $payload, ['ik-appid' => 'test-app', 'ik-sign' => $signature])->assertOk();
        }
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(18, $product->fresh()->inventory_count);
        $this->assertSame(1, Invoice::count());
        Notification::assertCount(1);

        $payload['status'] = 'FAILURE';
        $signature = hash_hmac('sha256', str_replace('"', '\\"', '/payments/ikhokha/webhook'.json_encode($payload)), 'test-secret');
        $this->postJson(route('payments.ikhokha.webhook'), $payload, ['ik-appid' => 'test-app', 'ik-sign' => $signature])->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(18, $product->fresh()->inventory_count);
    }
}
