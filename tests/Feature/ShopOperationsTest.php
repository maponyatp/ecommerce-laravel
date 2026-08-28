<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Orders\Pages\EditOrder;
use App\Filament\Admin\Resources\ShippingMethods\Pages\CreateShippingMethod;
use App\Filament\Admin\Resources\ShippingMethods\Pages\EditShippingMethod;
use App\Models\AdvancedAnalytics;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\OrderFulfillmentService;
use App\Services\ShippingService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ShopOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->current_team_id);
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        foreach (['view_any_order', 'view_order', 'update_order'] as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $user->assignRole($role);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    private function order(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'customer_email' => 'buyer@example.test', 'currency' => 'ZAR',
            'order_date' => now(), 'total_amount' => 100, 'payment_status' => 'paid',
            'shipping_status' => 'unfulfilled', 'status' => 'processing',
            'shipping_address' => '1 Flower Road, Johannesburg',
            'inventory_committed_at' => now(),
        ], $attributes))->fresh();
    }

    private function method(array $attributes = []): ShippingMethod
    {
        return ShippingMethod::create(array_merge([
            'name' => 'Local flower delivery', 'base_rate' => 20, 'weight_rate' => 0,
            'max_weight' => 100, 'is_active' => true,
        ], $attributes));
    }

    private function updateOrder(Order $order, string $status, User $admin, array $extra = []): Order
    {
        return app(OrderFulfillmentService::class)->update($order, array_merge([
            'fulfillment_version' => $order->fulfillment_version, 'shipping_status' => $status,
        ], $extra), $admin);
    }

    private function assertInvalid(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected a validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_fulfillment_sequence_has_audit_actor_tracking_and_timestamps_without_sending_email(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $order = $this->order();
        $service = app(OrderFulfillmentService::class);
        $order = $this->updateOrder($order, 'processing', $admin);
        $order = $this->updateOrder($order, 'shipped', $admin, [
            'delivery_carrier' => 'Local driver', 'supplier_tracking_number' => 'FLOWER-001',
            'tracking_url' => 'https://tracking.example.test/FLOWER-001',
        ]);
        $this->assertNotNull($order->shipped_at);
        $order = $this->updateOrder($order, 'delivered', $admin);
        $this->assertSame('completed', $order->status);
        $this->assertSame('paid', $order->payment_status);
        $this->assertNotNull($order->delivered_at);
        $this->assertSame(3, $order->fulfillment_version);
        $this->assertSame(3, OrderStatusHistory::where('changed_by', $admin->id)->count());
        $this->assertSame(0, OrderStatusHistory::where('customer_notified', true)->count());
        Notification::assertNothingSent();
    }

    public function test_fulfillment_blocks_unpaid_cancelled_and_uncommitted_orders(): void
    {
        $admin = $this->admin();
        foreach ([
            ['payment_status' => 'pending'], ['status' => 'cancelled'],
            ['payment_status' => 'refunded'], ['inventory_committed_at' => null],
            ['status' => 'payment_received_stock_review'],
        ] as $attributes) {
            $order = $this->order($attributes);
            $this->assertInvalid(fn () => $this->updateOrder($order, 'processing', $admin), 'shipping_status');
            $this->assertSame('unfulfilled', $order->fresh()->shipping_status);
        }
        $this->assertSame(0, OrderStatusHistory::count());
    }

    public function test_steps_cannot_be_skipped_reversed_or_used_to_return_an_order(): void
    {
        $admin = $this->admin();
        $order = $this->order();
        $this->assertInvalid(fn () => $this->updateOrder($order, 'delivered', $admin), 'shipping_status');
        $order = $this->updateOrder($order, 'processing', $admin);
        $this->assertInvalid(fn () => $this->updateOrder($order, 'unfulfilled', $admin), 'shipping_status');
        $this->assertInvalid(fn () => $this->updateOrder($order, 'returned', $admin), 'shipping_status');
    }

    public function test_dispatch_requires_carrier_and_reference_and_rejects_unsafe_tracking_urls(): void
    {
        $admin = $this->admin();
        $order = $this->order(['shipping_status' => 'processing']);
        $this->assertInvalid(fn () => $this->updateOrder($order, 'shipped', $admin), 'supplier_tracking_number');
        $this->assertInvalid(fn () => $this->updateOrder($order, 'shipped', $admin, [
            'delivery_carrier' => 'Driver', 'supplier_tracking_number' => '001', 'tracking_url' => 'javascript:alert(1)',
        ]), 'tracking_url');
        $this->assertNull($order->fresh()->shipped_at);
    }

    public function test_stale_form_cannot_overwrite_newer_staff_changes(): void
    {
        $admin = $this->admin();
        $order = $this->order();
        $this->updateOrder($order, 'processing', $admin);
        $this->assertInvalid(fn () => $this->updateOrder($order, 'processing', $admin, ['delivery_carrier' => 'Stale']), 'shipping_status');
        $this->assertNull($order->fresh()->delivery_carrier);
        $this->assertSame(1, OrderStatusHistory::count());
    }

    public function test_duplicate_save_is_a_no_op_and_posted_financial_fields_are_ignored(): void
    {
        $admin = $this->admin();
        $order = $this->order();
        $order = $this->updateOrder($order, 'processing', $admin, ['total_amount' => 1, 'payment_status' => 'refunded', 'status' => 'cancelled']);
        $this->updateOrder($order, 'processing', $admin);
        $this->assertSame('100.00', $order->fresh()->total_amount);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame(1, OrderStatusHistory::count());
    }

    public function test_customers_cannot_update_fulfillment_even_with_an_order_permission(): void
    {
        $user = User::factory()->create();
        $order = $this->order();
        $this->expectException(HttpException::class);
        $this->updateOrder($order, 'processing', $user);
    }

    public function test_staff_notes_are_private_and_escaped_in_admin_and_absent_from_customer_pages(): void
    {
        $admin = $this->admin();
        $order = $this->order();
        app(OrderFulfillmentService::class)->addInternalNote($order, '<script>privateSecret()</script>', $admin);
        $this->assertFalse(OrderNote::first()->customer_visible);
        $this->get('/admin/orders/'.$order->id.'/edit')->assertOk()
            ->assertSee('&lt;script&gt;privateSecret()&lt;/script&gt;', false);
        $this->get(URL::temporarySignedRoute('checkout.confirmation', now()->addHour(), ['order' => $order]))
            ->assertOk()->assertDontSee('privateSecret');
    }

    public function test_order_admin_livewire_save_updates_version_for_the_next_save(): void
    {
        $this->admin();
        $order = $this->order();
        Livewire::test(EditOrder::class, ['record' => $order->id])
            ->fillForm(['shipping_status' => 'processing'])->call('save')->assertHasNoFormErrors()
            ->assertSet('data.fulfillment_version', 1)
            ->fillForm(['shipping_status' => 'shipped', 'delivery_carrier' => 'Driver', 'supplier_tracking_number' => 'LOCAL-1'])
            ->call('save')->assertHasNoFormErrors()->assertSet('data.fulfillment_version', 2);
        $this->assertSame('shipped', $order->fresh()->shipping_status);
    }

    public function test_admin_pages_render_and_customers_cannot_access_them(): void
    {
        $this->admin();
        $method = $this->method();
        $this->get('/admin/orders')->assertOk();
        $this->get('/admin/shipping-methods')->assertOk()->assertSee('Local flower delivery');
        $this->get('/admin/shipping-methods/'.$method->id.'/edit')->assertOk()->assertSee('postal codes');
        $this->actingAs(User::factory()->create());
        $this->get('/admin/shipping-methods')->assertForbidden();
        $this->get('/shipping')->assertForbidden();
    }

    public function test_delivery_method_can_be_created_and_deactivated_from_admin(): void
    {
        $this->admin();
        Livewire::test(CreateShippingMethod::class)
            ->fillForm(['name' => 'Rose courier', 'estimated_delivery_time' => 'Next working day',
                'base_rate' => 25, 'weight_rate' => 0, 'max_weight' => 10, 'is_active' => true,
                'postal_codes' => ['0001', '2001']])
            ->call('create')->assertHasNoFormErrors();
        $method = ShippingMethod::firstOrFail();
        $this->assertSame(['0001', '2001'], $method->postal_codes);
        Livewire::test(EditShippingMethod::class, ['record' => $method->id])
            ->fillForm(['is_active' => false])->call('save')->assertHasNoFormErrors();
        $this->assertFalse($method->fresh()->is_active);
        $this->assertCount(0, app(ShippingService::class)->getAvailableShippingMethods());
    }

    public function test_negative_rates_and_invalid_postal_codes_fail_admin_validation(): void
    {
        $this->admin();
        Livewire::test(CreateShippingMethod::class)
            ->fillForm(['name' => 'Bad courier', 'estimated_delivery_time' => 'Tomorrow',
                'base_rate' => -1, 'weight_rate' => 0, 'max_weight' => 10, 'postal_codes' => ['XYZ']])
            ->call('create')->assertHasFormErrors();
        $this->assertSame(0, ShippingMethod::count());
    }

    public function test_shipping_filters_disabled_methods_and_postal_areas_with_leading_zeroes(): void
    {
        $method = $this->method(['postal_codes' => ['0001']]);
        $this->method(['is_active' => false]);
        $service = app(ShippingService::class);
        $cart = [['weight' => 1, 'quantity' => 1]];
        $this->assertCount(1, $service->getAvailableShippingMethods($cart, 'Street', '0001'));
        $this->assertCount(0, $service->getAvailableShippingMethods($cart, 'Street', '2001'));
        $this->assertCount(0, $service->getAvailableShippingMethods($cart, 'Street'));
    }

    public function test_quote_rejects_disabled_and_outside_area_delivery_without_creating_an_order(): void
    {
        $category = ProductCategory::create(['name' => 'Flowers', 'slug' => 'flowers']);
        $product = Product::create(['name' => 'Roses', 'slug' => 'roses', 'category_id' => $category->id, 'price' => 100, 'inventory_count' => 10]);
        $method = $this->method(['postal_codes' => ['0001']]);
        $address = ['shipping_address' => '1 Main Street', 'shipping_country' => 'ZA',
            'shipping_city' => 'Johannesburg', 'shipping_postal_code' => '2001',
            'shipping_method_id' => $method->id, 'delivery_contact_name' => 'Recipient', 'delivery_phone' => '+27821234567'];
        $this->withSession(['cart' => [$product->id => ['quantity' => 1]]])
            ->postJson(route('checkout.quote'), $address)->assertUnprocessable()->assertJsonValidationErrors('shipping_method_id');
        $address['shipping_postal_code'] = '0001';
        $this->postJson(route('checkout.quote'), $address)->assertOk()->assertJson(['shipping' => 20]);
        $method->update(['is_active' => false]);
        $this->postJson(route('checkout.quote'), $address)->assertUnprocessable();
        $this->assertSame(0, Order::count());
    }

    public function test_sales_metrics_do_not_mix_currencies_or_count_pending_orders(): void
    {
        $this->order(['total_amount' => 100]);
        $this->order(['total_amount' => 500, 'currency' => 'USD']);
        $this->order(['total_amount' => 500, 'currency' => null]);
        $this->order(['total_amount' => 500, 'payment_status' => 'pending']);
        $service = app(AnalyticsService::class);
        $metrics = $service->getSalesMetrics();
        $this->assertSame(100.0, $metrics['total_revenue']);
        $this->assertSame(1, $metrics['order_count']);
        $this->assertSame(2, $metrics['excluded_currency_orders']);
        $this->assertSame('ZAR', $metrics['currency']);
        $this->assertEquals(100, $service->getSalesTrends()[0]['total_revenue']);
    }

    public function test_low_stock_count_is_not_capped_at_twenty_preview_rows(): void
    {
        $category = ProductCategory::create(['name' => 'Flowers', 'slug' => 'flowers']);
        for ($i = 0; $i < 25; $i++) {
            Product::create(['name' => 'Flower '.$i, 'slug' => 'flower-'.$i, 'category_id' => $category->id,
                'price' => 10, 'inventory_count' => 1, 'low_stock_threshold' => 5]);
        }
        $this->assertSame(25, app(AnalyticsService::class)->getInventoryInsights()['low_stock_count']);
    }

    public function test_customer_tracking_displays_safe_urls_but_not_legacy_unsafe_links(): void
    {
        $order = $this->order(['delivery_carrier' => 'Flower driver', 'supplier_tracking_number' => 'SAFE-123',
            'tracking_url' => 'https://tracking.example.test/SAFE-123']);
        $url = URL::temporarySignedRoute('checkout.confirmation', now()->addHour(), ['order' => $order]);
        $this->get($url)->assertOk()->assertSee('Track delivery')->assertSee('SAFE-123');
        $order->update(['tracking_url' => 'javascript:alert(1)']);
        $this->get($url)->assertOk()->assertDontSee('javascript:alert')->assertDontSee('Track delivery');
    }

    public function test_reports_reject_unrecognised_query_identifiers(): void
    {
        $this->assertInvalid(fn () => AdvancedAnalytics::queryCube('sales', 'product_id', 'revenue) from users --'), 'selectedMeasure');
        $this->assertInvalid(fn () => AdvancedAnalytics::queryCube('sales', 'bad_column', 'revenue'), 'selectedMeasure');
        $this->assertInvalid(fn () => AdvancedAnalytics::queryCube('customers', 'customer_segment', 'lifetime_value'), 'selectedMeasure');
    }

    public function test_reports_use_paid_order_items_not_unverified_performance_revenue(): void
    {
        $this->admin();
        $category = ProductCategory::create(['name' => 'Flowers', 'slug' => 'flowers']);
        $product = Product::create(['name' => 'Report roses', 'slug' => 'report-roses', 'category_id' => $category->id, 'price' => 20, 'inventory_count' => 10]);
        foreach ([['currency' => 'ZAR'], ['currency' => 'USD'], ['currency' => null], ['payment_status' => 'pending']] as $attributes) {
            $this->order($attributes)->items()->create(['product_id' => $product->id, 'price' => 20, 'quantity' => 2]);
        }
        foreach (['product_id', 'category_id', 'date'] as $dimension) {
            $data = AdvancedAnalytics::queryCube('sales', $dimension, 'revenue');
            $this->assertCount(1, $data);
            $this->assertEquals(40, $data[0]->value);
        }
        $this->get('/admin/reports')->assertOk()->assertSee('ZAR 40.00');
    }
}
