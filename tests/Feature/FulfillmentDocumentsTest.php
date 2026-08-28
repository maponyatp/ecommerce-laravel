<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\DeliveryOperations;
use App\Filament\Admin\Resources\Orders\Pages\EditOrder;
use App\Filament\Admin\Resources\Orders\Pages\ListOrders;
use App\Models\DeliveryBooking;
use App\Models\DeliverySlot;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FulfillmentDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::preventStrayRequests();
    }

    private function admin(bool $permissions = true): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->current_team_id);
        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        if ($permissions) {
            foreach (['view_any_order', 'view_order', 'update_order'] as $permission) {
                $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
            }
        }
        $user->assignRole($role);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    private function product(bool $digital = false): Product
    {
        $category = ProductCategory::create(['name' => 'Flowers', 'slug' => 'flowers-'.uniqid()]);

        return Product::create(['name' => $digital ? 'Digital flower guide' : 'Rose bouquet', 'slug' => 'item-'.uniqid(),
            'category_id' => $category->id, 'price' => 0, 'inventory_count' => 20, 'is_downloadable' => $digital]);
    }

    private function order(array $attributes = [], bool $snapshot = true): Order
    {
        $order = Order::create(array_merge(['customer_email' => 'PRIVATE-BUYER@example.test', 'order_date' => now(),
            'currency' => 'ZAR', 'total_amount' => 1234.56, 'payment_status' => 'paid', 'status' => 'processing',
            'shipping_status' => 'unfulfilled', 'shipping_address' => '1 Flower Street, Johannesburg 2000',
            'delivery_contact_name' => 'Flower recipient', 'delivery_phone' => '+27821234567',
            'gift_message' => 'Happy birthday!', 'inventory_committed_at' => now(), 'is_dropshipped' => false], $attributes));
        $order->items()->create(['product_id' => $this->product()->id, 'quantity' => 2, 'price' => 617.28,
            'product_name_snapshot' => $snapshot ? 'Purchased rose bouquet' : null,
            'is_downloadable_snapshot' => $snapshot ? false : null]);

        return $order->fresh();
    }

    private function method(): ShippingMethod
    {
        return ShippingMethod::create(['name' => 'Delivery '.uniqid(), 'base_rate' => 0, 'weight_rate' => 0,
            'max_weight' => 100, 'is_active' => true]);
    }

    private function schedule(Order $order, string $start = '2026-09-01 08:00:00', ?ShippingMethod $method = null, string $status = 'confirmed'): Order
    {
        $method ??= $this->method();
        $slot = DeliverySlot::create(['shipping_method_id' => $method->id, 'starts_at' => $start,
            'ends_at' => Carbon::parse($start)->addHour(), 'booking_closes_at' => Carbon::parse($start)->subDay(),
            'capacity' => 10, 'is_active' => false]);
        DeliveryBooking::create(['order_id' => $order->id, 'delivery_slot_id' => $slot->id, 'status' => $status,
            'expires_at' => $status === 'held' ? now()->addMinutes(10) : null]);
        $order->update(['shipping_method_id' => $method->id, 'delivery_slot_id' => $slot->id,
            'delivery_scheduled_at' => $slot->starts_at, 'delivery_window_end' => $slot->ends_at]);

        return $order->fresh();
    }

    public function test_staff_packing_slip_is_private_price_free_and_excludes_internal_notes(): void
    {
        $this->admin();
        $order = $this->order();
        $order->notes()->create(['note' => 'SECRET STAFF NOTE', 'type' => 'internal', 'customer_visible' => false]);
        $before = $order->getAttributes();
        $response = $this->get(route('operations.packing-slip', $order))->assertOk()
            ->assertSee('Purchased rose bouquet')->assertSee('Flower recipient')->assertSee('Happy birthday!')
            ->assertDontSee('PRIVATE-BUYER')->assertDontSee('1234.56')->assertDontSee('617.28')->assertDontSee('SECRET STAFF NOTE')
            ->assertDontSee('livewire')->assertDontSee('js.stripe.com');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertDatabaseCount('inventory_logs', 0);
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_customer_and_guest_cannot_print_even_with_valid_signature(): void
    {
        $order = $this->order();
        $signed = URL::temporarySignedRoute('operations.packing-slip', now()->addHour(), ['order' => $order->id]);
        $this->get($signed)->assertRedirect();
        $owner = User::factory()->create();
        $order->update(['user_id' => $owner->id]);
        $this->actingAs($owner)->get($signed)->assertForbidden();
        $this->get(route('operations.delivery-list'))->assertForbidden();
    }

    public function test_admin_role_without_order_permissions_cannot_access_documents(): void
    {
        $this->admin(false);
        $this->get(route('operations.packing-slip', $this->order()))->assertForbidden();
        $this->get(route('operations.delivery-list'))->assertForbidden();
        $this->assertFalse(DeliveryOperations::canAccess());
    }

    public function test_paid_stock_and_fulfillment_guards_block_unsafe_slips(): void
    {
        $this->admin();
        foreach ([
            ['payment_status' => 'pending'], ['payment_status' => 'refunded'], ['inventory_committed_at' => null],
            ['status' => 'cancelled'], ['status' => 'payment_received_stock_review'], ['status' => 'payment_received_delivery_review'],
            ['status' => 'supplier_queued'], ['is_dropshipped' => true], ['shipping_status' => 'not_required'],
            ['shipping_status' => 'returned'], ['shipping_address' => null],
        ] as $attributes) {
            $this->get(route('operations.packing-slip', $this->order($attributes)))->assertStatus(409);
        }
    }

    public function test_delivery_hold_is_not_a_confirmed_booking_for_printing(): void
    {
        $this->admin();
        $order = $this->schedule($this->order(), status: 'held');
        $this->get(route('operations.packing-slip', $order))->assertStatus(409);
        $order->deliveryBooking->update(['status' => 'confirmed']);
        $this->get(route('operations.packing-slip', $order))->assertOk()->assertSee('10:00–11:00');
    }

    public function test_digital_items_are_not_marked_for_packing_in_mixed_order(): void
    {
        $this->admin();
        $order = $this->order();
        $order->items()->create(['product_id' => $this->product(true)->id, 'quantity' => 1, 'price' => 0,
            'product_name_snapshot' => 'Purchased digital guide', 'is_downloadable_snapshot' => true]);
        $this->get(route('operations.packing-slip', $order))->assertOk()->assertSee('Purchased digital guide')
            ->assertSee('Digital — do not pack')->assertSee('Physical item');
        $order->items()->where('is_downloadable_snapshot', false)->delete();
        $this->get(route('operations.packing-slip', $order))->assertStatus(409);
    }

    public function test_purchase_snapshot_survives_product_rename_and_type_change(): void
    {
        $this->admin();
        $order = $this->order();
        $order->items()->first()->product->update(['name' => 'NEW CATALOGUE NAME', 'is_downloadable' => true]);
        $this->get(route('operations.packing-slip', $order))->assertOk()->assertSee('Purchased rose bouquet')
            ->assertDontSee('NEW CATALOGUE NAME')->assertSee('Physical item')->assertDontSee('Legacy order:');
    }

    public function test_legacy_items_are_labelled_without_backfilling_history(): void
    {
        $this->admin();
        $order = $this->order(snapshot: false);
        $this->get(route('operations.packing-slip', $order))->assertOk()->assertSee('Legacy order:')
            ->assertSee('Rose bouquet');
        $this->assertNull($order->items()->first()->product_name_snapshot);
    }

    public function test_printing_delivered_order_is_explicitly_a_reprint(): void
    {
        $this->admin();
        $order = $this->order(['shipping_status' => 'delivered', 'status' => 'completed']);
        $this->get(route('operations.packing-slip', $order))->assertOk()->assertSee('REPRINT');
    }

    public function test_daily_list_uses_south_african_midnight_not_utc_or_order_creation_date(): void
    {
        $this->admin();
        $early = $this->schedule($this->order(['delivery_contact_name' => 'Included midnight']), '2026-08-31 22:00:00');
        $late = $this->schedule($this->order(['delivery_contact_name' => 'Included late']), '2026-09-01 21:59:59');
        $this->schedule($this->order(['delivery_contact_name' => 'Excluded before']), '2026-08-31 21:59:59');
        $this->schedule($this->order(['delivery_contact_name' => 'Excluded next day']), '2026-09-01 22:00:00');
        $this->get(route('operations.delivery-list', ['date' => '2026-09-01']))->assertOk()
            ->assertSee('Included midnight')->assertSee('Included late')->assertDontSee('Excluded before')->assertDontSee('Excluded next day')
            ->assertSee('2 deliveries');
    }

    public function test_daily_filters_exclude_unscheduled_unpaid_unconfirmed_and_delivered_by_default(): void
    {
        $this->admin();
        $method = $this->method();
        $this->schedule($this->order(['delivery_contact_name' => 'Included active']), method: $method);
        $this->schedule($this->order(['delivery_contact_name' => 'Excluded delivered', 'shipping_status' => 'delivered', 'status' => 'completed']), method: $method);
        $this->schedule($this->order(['delivery_contact_name' => 'Excluded unpaid', 'payment_status' => 'pending']), method: $method);
        $this->schedule($this->order(['delivery_contact_name' => 'Excluded hold']), method: $method, status: 'held');
        $this->schedule($this->order(['delivery_contact_name' => 'Excluded method']));
        $this->order(['delivery_contact_name' => 'Unscheduled recipient']);
        $this->get(route('operations.delivery-list', ['date' => '2026-09-01', 'shipping_method_id' => $method->id]))
            ->assertOk()->assertSee('Included active')->assertDontSee('Excluded delivered')->assertDontSee('Excluded unpaid')
            ->assertDontSee('Excluded hold')->assertDontSee('Excluded method')->assertDontSee('Unscheduled recipient')
            ->assertSee('1 eligible undelivered orders');
        $this->get(route('operations.delivery-list', ['date' => '2026-09-01', 'state' => 'delivered']))
            ->assertOk()->assertSee('Excluded delivered')->assertDontSee('Included active');
    }

    public function test_invalid_dates_and_filters_are_rejected(): void
    {
        $this->admin();
        foreach ([['date' => '2026-02-30'], ['date' => 'invalid'], ['state' => 'cancelled'],
            ['shipping_method_id' => 999999], ['print' => 'anything']] as $filters) {
            $this->getJson(route('operations.delivery-list', $filters))->assertUnprocessable();
        }
    }

    public function test_print_view_includes_every_filtered_page_and_no_private_financial_data(): void
    {
        $this->admin();
        $prototype = $this->schedule($this->order());
        for ($i = 0; $i < 50; $i++) {
            $order = $prototype->replicate();
            $order->delivery_contact_name = 'Recipient copy '.$i;
            $order->save();
            $item = $prototype->items()->first()->replicate();
            $item->order_id = $order->id;
            $item->save();
            DeliveryBooking::create(['order_id' => $order->id, 'delivery_slot_id' => $prototype->delivery_slot_id, 'status' => 'confirmed']);
        }
        $query = ['date' => '2026-09-01'];
        $this->get(route('operations.delivery-list', $query))->assertOk()->assertSee('51 deliveries')
            ->assertSee('This screen is paginated')->assertDontSee('Recipient copy 49');
        $response = $this->get(route('operations.delivery-list', [...$query, 'print' => 1]))
            ->assertOk()->assertSee('Recipient copy 49')->assertDontSee('This screen is paginated')
            ->assertDontSee('PRIVATE-BUYER')->assertDontSee('1234.56')->assertDontSee('Happy birthday!');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_print_limit_never_silently_returns_a_partial_delivery_list(): void
    {
        $this->admin();
        $prototype = $this->schedule($this->order());
        $orders = $items = $bookings = [];
        for ($i = 1; $i <= 500; $i++) {
            $id = $prototype->id + $i;
            $orders[] = [...$prototype->getAttributes(), 'id' => $id];
            $items[] = ['order_id' => $id, 'product_id' => $prototype->items->first()->product_id,
                'quantity' => 1, 'price' => 0, 'is_downloadable_snapshot' => false];
            $bookings[] = ['order_id' => $id, 'delivery_slot_id' => $prototype->delivery_slot_id,
                'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($orders, 50) as $chunk) {
            DB::table('orders')->insert($chunk);
        }
        DB::table('order_items')->insert($items);
        DB::table('delivery_bookings')->insert($bookings);
        $this->get(route('operations.delivery-list', ['date' => '2026-09-01', 'print' => 1]))
            ->assertUnprocessable()->assertSee('More than 500 deliveries match');
    }

    public function test_documents_use_central_brand_name_and_logo(): void
    {
        $this->admin();
        $settings = app(GeneralSettings::class);
        $settings->site_name = 'Central Flower Brand';
        $settings->site_logo_path = 'logos/central.png';
        $settings->save();
        $this->get(route('operations.packing-slip', $this->order()))->assertOk()
            ->assertSee('Central Flower Brand')->assertSee('/storage/logos/central.png', false);
    }

    public function test_admin_navigation_and_document_actions_render(): void
    {
        $this->admin();
        $order = $this->order();
        Livewire::test(DeliveryOperations::class)->assertSuccessful()->assertSee('Open daily delivery list');
        Livewire::test(ListOrders::class)->assertSuccessful()->assertSee('Daily delivery list');
        Livewire::test(EditOrder::class, ['record' => $order->id])->assertSuccessful()->assertActionVisible('packingSlip');
        $order->update(['payment_status' => 'pending']);
        Livewire::test(EditOrder::class, ['record' => $order->id])->assertActionHidden('packingSlip');
    }

    public function test_checkout_saves_server_owned_item_snapshots(): void
    {
        $product = $this->product();
        $method = $this->method();
        $this->withSession(['cart' => [$product->id => ['quantity' => 1, 'name' => 'FORGED CART NAME', 'is_downloadable' => true]]])
            ->post(route('checkout.process'), ['email' => 'buyer@example.test', 'shipping_method_id' => $method->id,
                'shipping_address' => '1 Flower Road', 'shipping_city' => 'Johannesburg', 'shipping_country' => 'ZA',
                'shipping_postal_code' => '2000', 'delivery_contact_name' => 'Recipient', 'delivery_phone' => '+27821234567'])
            ->assertRedirect();
        $item = Order::firstOrFail()->items()->firstOrFail();
        $this->assertSame('Rose bouquet', $item->product_name_snapshot);
        $this->assertFalse($item->is_downloadable_snapshot);
    }
}
