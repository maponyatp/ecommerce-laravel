<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\DeliverySlots\Pages\CreateDeliverySlot;
use App\Filament\Admin\Resources\DeliverySlots\Pages\EditDeliverySlot;
use App\Filament\Admin\Resources\DeliverySlots\Pages\ListDeliverySlots;
use App\Filament\Admin\Resources\Orders\Pages\EditOrder;
use App\Models\DeliveryBooking;
use App\Models\DeliverySlot;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\DeliverySchedulingService;
use App\Services\DeliverySlotManagementService;
use App\Services\OrderFulfillmentService;
use App\Services\OrderPaymentService;
use App\Services\StockReservationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DeliverySchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 1)->setTime(7, 0));
        Notification::fake();
        Http::preventStrayRequests();
    }

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

    private function method(bool $scheduled = true): ShippingMethod
    {
        return ShippingMethod::create(['name' => 'Local flowers', 'base_rate' => 0, 'weight_rate' => 0,
            'max_weight' => 100, 'is_active' => true, 'requires_delivery_slot' => $scheduled]);
    }

    private function slot(?ShippingMethod $method = null, array $data = []): DeliverySlot
    {
        return DeliverySlot::create(array_merge(['shipping_method_id' => ($method ?? $this->method())->id,
            'starts_at' => now()->addDay()->setTime(8, 0), 'ends_at' => now()->addDay()->setTime(10, 0),
            'booking_closes_at' => now()->addHours(2), 'capacity' => 1, 'is_active' => true], $data))->fresh();
    }

    private function product(): Product
    {
        $category = ProductCategory::create(['name' => 'Flowers', 'slug' => 'flowers-'.uniqid()]);

        return Product::create(['name' => 'Bouquet', 'slug' => 'bouquet-'.uniqid(), 'category_id' => $category->id,
            'price' => 0, 'inventory_count' => 20, 'is_downloadable' => false]);
    }

    private function reserve(DeliverySlot $slot, ?Product $product = null, ?array $quoted = null): Order
    {
        $product ??= $this->product();
        $quoted ??= app(DeliverySchedulingService::class)->quote($slot->shippingMethod, $slot->id);

        return DB::transaction(function () use ($slot, $product, $quoted) {
            $order = Order::create(['customer_email' => 'buyer@example.test', 'order_date' => now(),
                'currency' => 'ZAR', 'total_amount' => 0, 'payment_status' => 'pending', 'payment_method' => 'free',
                'shipping_method_id' => $slot->shipping_method_id, 'shipping_address' => '1 Flower Road',
                'shipping_status' => 'unfulfilled', 'status' => 'pending']);
            $order->items()->create(['product_id' => $product->id, 'quantity' => 1, 'price' => 0]);
            app(StockReservationService::class)->reserve($order);
            app(DeliverySchedulingService::class)->reserve($order, $slot->id, $quoted);

            return $order->fresh();
        });
    }

    private function address(ShippingMethod $method, ?DeliverySlot $slot = null): array
    {
        return ['email' => 'buyer@example.test', 'shipping_method_id' => $method->id,
            'shipping_address' => '1 Flower Road', 'shipping_city' => 'Johannesburg', 'shipping_region' => 'Gauteng',
            'shipping_country' => 'ZA', 'shipping_postal_code' => '2000',
            'delivery_contact_name' => 'Recipient', 'delivery_phone' => '+27821234567',
            'delivery_slot_id' => $slot?->id];
    }

    private function invalid(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_scheduling_is_opt_in_and_legacy_checkout_still_works(): void
    {
        $method = ShippingMethod::create(['name' => 'Standard', 'base_rate' => 0, 'weight_rate' => 0, 'max_weight' => 100])->fresh();
        $this->assertFalse($method->requires_delivery_slot);
        $product = $this->product();
        $this->withSession(['cart' => [$product->id => ['quantity' => 1]]])
            ->post(route('checkout.process'), $this->address($method))->assertRedirect();
        $this->assertSame('paid', Order::first()->payment_status);
        $this->assertDatabaseCount('delivery_bookings', 0);
    }

    public function test_scheduled_checkout_quotes_and_books_a_window_with_local_labels(): void
    {
        $slot = $this->slot();
        $product = $this->product();
        $this->withSession(['cart' => [$product->id => ['quantity' => 1]]])->get(route('checkout.initiate'))
            ->assertOk()->assertSee('Choose your flower delivery window')->assertSee('10:00–12:00');
        $this->postJson(route('checkout.quote'), $this->address($slot->shippingMethod, $slot))->assertOk()
            ->assertJsonPath('delivery_slot.id', $slot->id);
        $this->post(route('checkout.process'), $this->address($slot->shippingMethod, $slot))->assertRedirect();
        $order = Order::first();
        $this->assertSame('confirmed', $order->deliveryBooking->status);
        $this->assertSame('processing', $order->status);
        $this->assertSame($slot->id, $order->delivery_slot_id);
        $this->assertTrue($order->delivery_scheduled_at->equalTo($slot->starts_at));
        $this->assertSame(19, $product->fresh()->inventory_count);
    }

    public function test_required_closed_wrong_method_and_full_windows_are_rejected(): void
    {
        $slot = $this->slot();
        $service = app(DeliverySchedulingService::class);
        $this->invalid(fn () => $service->quote($slot->shippingMethod, null), 'delivery_slot_id');
        $this->invalid(fn () => $service->quote($this->method(), $slot->id), 'delivery_slot_id');
        $slot->update(['is_active' => false]);
        $this->invalid(fn () => $service->quote($slot->shippingMethod, $slot->id), 'delivery_slot_id');
        $slot->update(['is_active' => true]);
        $this->reserve($slot);
        $this->invalid(fn () => $service->quote($slot->shippingMethod, $slot->id), 'delivery_slot_id');
    }

    public function test_cutoff_is_exclusive_and_holds_never_extend_past_it(): void
    {
        $slot = $this->slot(null, ['booking_closes_at' => now()->addMinutes(10)]);
        $order = $this->reserve($slot);
        $this->assertTrue($order->deliveryBooking->expires_at->equalTo($slot->booking_closes_at));
        $this->travel(10)->minutes();
        $this->invalid(fn () => app(DeliverySchedulingService::class)->quote($slot->shippingMethod, $slot->id), 'delivery_slot_id');
        app(OrderPaymentService::class)->settleFree($order);
        $this->assertSame('payment_received_delivery_review', $order->fresh()->status);
    }

    public function test_capacity_is_rechecked_under_lock_and_failed_reservation_rolls_back_stock_and_order(): void
    {
        $slot = $this->slot();
        $product = $this->product();
        $quote = app(DeliverySchedulingService::class)->quote($slot->shippingMethod, $slot->id);
        $this->reserve($slot, $product, $quote);
        $this->invalid(fn () => $this->reserve($slot, $product, $quote), 'delivery_slot_id');
        $this->assertSame(1, Order::count());
        $this->assertDatabaseCount('stock_reservations', 1);
        $this->assertSame(19, app(StockReservationService::class)->available($product));
    }

    public function test_window_changes_between_quote_and_reserve_are_rejected(): void
    {
        $slot = $this->slot();
        $quote = app(DeliverySchedulingService::class)->quote($slot->shippingMethod, $slot->id);
        $slot->update(['ends_at' => $slot->ends_at->addHour()]);
        $this->invalid(fn () => $this->reserve($slot, null, $quote), 'delivery_slot_id');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_failed_payment_releases_delivery_and_stock_without_restoring_on_hand(): void
    {
        $slot = $this->slot();
        $order = $this->reserve($slot);
        $transaction = PaymentTransaction::create(['order_id' => $order->id, 'gateway' => 'ikhokha',
            'external_transaction_id' => 'test-failure', 'amount' => 0, 'currency' => 'ZAR', 'status' => 'pending']);
        app(OrderPaymentService::class)->markFailed($transaction);
        $this->assertSame('released', $order->fresh()->deliveryBooking->status);
        $this->assertSame(1, app(DeliverySchedulingService::class)->available($slot));
        $this->assertSame('released', $order->fresh()->stock_reservation_status);
    }

    public function test_expired_hold_can_be_reallocated_but_late_payment_cannot_overbook(): void
    {
        $slot = $this->slot();
        $first = $this->reserve($slot);
        $this->travel(31)->minutes();
        app(StockReservationService::class)->expire();
        $second = $this->reserve($slot);
        app(OrderPaymentService::class)->settleFree($first);
        $this->assertSame('payment_received_delivery_review', $first->fresh()->status);
        $this->assertSame('released', $first->fresh()->deliveryBooking->status);
        app(OrderPaymentService::class)->settleFree($second);
        $this->assertSame(1, DeliveryBooking::where('status', 'confirmed')->count());
        $this->assertNotNull($first->fresh()->inventory_committed_at);
    }

    public function test_late_payment_can_reacquire_available_capacity_once(): void
    {
        $slot = $this->slot();
        $order = $this->reserve($slot);
        $this->travel(31)->minutes();
        app(StockReservationService::class)->expire();
        app(OrderPaymentService::class)->settleFree($order);
        app(OrderPaymentService::class)->settleFree($order);
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame(1, DeliveryBooking::where('status', 'confirmed')->count());
    }

    public function test_unpublishing_honours_existing_holds_and_confirmed_bookings(): void
    {
        $slot = $this->slot();
        $order = $this->reserve($slot);
        $slot->update(['is_active' => false]);
        app(OrderPaymentService::class)->settleFree($order);
        $this->assertSame('confirmed', $order->fresh()->deliveryBooking->status);
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertCount(0, app(DeliverySchedulingService::class)->choices($slot->shipping_method_id));
    }

    public function test_stock_review_releases_delivery_capacity(): void
    {
        $slot = $this->slot();
        $product = $this->product();
        $order = $this->reserve($slot, $product);
        $product->update(['inventory_count' => 0]);
        app(OrderPaymentService::class)->settleFree($order);
        $this->assertSame('payment_received_stock_review', $order->fresh()->status);
        $this->assertSame('released', $order->fresh()->deliveryBooking->status);
    }

    public function test_calendar_blocks_booked_date_edits_capacity_reduction_and_stale_saves(): void
    {
        $admin = $this->admin();
        $slot = $this->slot(null, ['capacity' => 2]);
        $this->reserve($slot);
        $this->reserve($slot);
        $service = app(DeliverySlotManagementService::class);
        $this->invalid(fn () => $service->save($slot, [...$slot->toArray(), 'capacity' => 1], $admin), 'capacity');
        $this->invalid(fn () => $service->save($slot, [...$slot->toArray(), 'starts_at' => $slot->starts_at->copy()->addHour()], $admin), 'starts_at');
        $data = [...$slot->toArray(), 'is_active' => false];
        $service->save($slot, $data, $admin);
        $this->invalid(fn () => $service->save($slot, $data, $admin), 'capacity');
        $this->assertSame(2, $slot->fresh()->capacity);
    }

    public function test_calendar_rejects_cross_day_and_past_dates(): void
    {
        $admin = $this->admin();
        $slot = $this->slot();
        $service = app(DeliverySlotManagementService::class);
        $this->invalid(fn () => $service->save(null, [...$slot->toArray(),
            'starts_at' => '2026-09-02 21:00:00', 'ends_at' => '2026-09-02 23:00:00'], $admin), 'ends_at');
        $this->invalid(fn () => $service->save(null, [...$slot->toArray(),
            'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHour(),
            'booking_closes_at' => now()->subDays(2)], $admin), 'starts_at');
    }

    public function test_admin_calendar_create_edit_and_listing_work_with_south_african_timezone(): void
    {
        $this->admin();
        $method = $this->method();
        Livewire::test(CreateDeliverySlot::class)->fillForm([
            'shipping_method_id' => $method->id, 'capacity' => 5, 'is_active' => false,
            'starts_at' => '2026-09-02 10:00:00', 'ends_at' => '2026-09-02 12:00:00',
            'booking_closes_at' => '2026-09-01 16:00:00',
        ])->call('create')->assertHasNoFormErrors();
        $slot = DeliverySlot::firstOrFail();
        $this->assertSame('08:00', $slot->starts_at->format('H:i'));
        $this->assertFalse($slot->is_active);
        Livewire::test(EditDeliverySlot::class, ['record' => $slot->id])
            ->fillForm(['is_active' => true])->call('save')->assertHasNoFormErrors()
            ->fillForm(['capacity' => 6])->call('save')->assertHasNoFormErrors();
        $this->assertSame(2, $slot->fresh()->version);
        $this->assertSame(6, $slot->fresh()->capacity);
        Livewire::test(ListDeliverySlots::class)->assertSuccessful()->assertCanSeeTableRecords([$slot]);
    }

    public function test_booked_window_validation_is_visible_in_admin_form(): void
    {
        $this->admin();
        $slot = $this->slot(null, ['capacity' => 2]);
        $this->reserve($slot);
        $this->reserve($slot);
        Livewire::test(EditDeliverySlot::class, ['record' => $slot->id])
            ->fillForm(['capacity' => 1])->call('save')->assertHasFormErrors(['capacity']);
        $this->assertSame(2, $slot->fresh()->capacity);
    }

    public function test_unpaid_fulfillment_validation_is_visible_in_admin_form(): void
    {
        $this->admin();
        $order = $this->reserve($this->slot());
        Livewire::test(EditOrder::class, ['record' => $order->id])
            ->fillForm(['shipping_status' => 'processing'])->call('save')->assertHasFormErrors(['shipping_status']);
        $this->assertSame('unfulfilled', $order->fresh()->shipping_status);
    }

    public function test_non_admins_cannot_manage_delivery_calendar(): void
    {
        $user = User::factory()->create();
        $this->assertFalse(Gate::forUser($user)->allows('create', DeliverySlot::class));
        $this->assertFalse(Gate::forUser($user)->allows('viewAny', DeliverySlot::class));
        $this->assertFalse(Gate::forUser($this->admin())->allows('delete', $this->slot()));
    }

    public function test_delivery_review_blocks_dispatch_and_admin_can_resolve_once_with_audit(): void
    {
        $admin = $this->admin();
        $slot = $this->slot(null, ['booking_closes_at' => now()->addMinutes(5)]);
        $order = $this->reserve($slot);
        $this->travel(6)->minutes();
        app(OrderPaymentService::class)->settleFree($order);
        $order->refresh();
        $this->invalid(fn () => app(OrderFulfillmentService::class)->update($order,
            ['fulfillment_version' => 0, 'shipping_status' => 'processing'], $admin), 'shipping_status');
        $replacement = $this->slot($slot->shippingMethod);
        Livewire::test(EditOrder::class, ['record' => $order->id])
            ->callAction('resolveDelivery', data: ['delivery_slot_id' => $replacement->id, 'note' => 'Customer agreed by phone.'])
            ->assertHasNoActionErrors();
        $order->refresh();
        $this->assertSame('processing', $order->status);
        $this->assertSame($replacement->id, $order->deliveryBooking->delivery_slot_id);
        $this->assertSame('confirmed', $order->deliveryBooking->status);
        $this->assertSame(1, $order->statusHistory()->where('changed_by', $admin->id)->count());
        $this->assertFalse((bool) $order->statusHistory()->first()->customer_notified);
        $this->invalid(fn () => app(DeliverySchedulingService::class)->resolvePaidReview($order, $replacement->id, 'Replay', $admin), 'delivery_slot_id');
    }

    public function test_signed_order_page_explains_unconfirmed_window_and_is_private(): void
    {
        $slot = $this->slot(null, ['booking_closes_at' => now()->addMinutes(5)]);
        $order = $this->reserve($slot);
        $this->travel(6)->minutes();
        app(OrderPaymentService::class)->settleFree($order);
        $url = URL::temporarySignedRoute('checkout.confirmation', now()->addHour(), ['order' => $order->id]);
        $response = $this->get($url)->assertOk()->assertSee('Payment received, but this delivery window is not confirmed.');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->get(route('checkout.confirmation', $order))->assertForbidden();
    }

    public function test_tampered_optional_window_and_missing_required_window_cannot_create_orders(): void
    {
        $slot = $this->slot();
        $product = $this->product();
        $this->withSession(['cart' => [$product->id => ['quantity' => 1]]])
            ->postJson(route('checkout.process'), $this->address($slot->shippingMethod))
            ->assertUnprocessable()->assertJsonValidationErrors('delivery_slot_id');
        $this->postJson(route('checkout.quote'), $this->address($this->method(false), $slot))
            ->assertUnprocessable()->assertJsonValidationErrors('delivery_slot_id');
        $this->assertDatabaseCount('orders', 0);
    }
}
