<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Pages\ManageGeneralSettings;
use App\Filament\Admin\Resources\Orders\Pages\EditOrder;
use App\Filament\Admin\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderFulfillmentService;
use App\Settings\GeneralSettings;
use App\Support\OrderWorkspace;
use App\Support\StoreSetupGuide;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminUsabilityTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'super_admin'): User
    {
        $user = User::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        if ($role === 'super_admin') {
            Gate::before(fn ($actor) => $actor->id === $user->id ? true : null);
        }

        return $user;
    }

    private function order(array $data = []): Order
    {
        return Order::create($data + ['customer_email' => 'buyer@example.test', 'order_date' => now(), 'total_amount' => 100, 'currency' => 'ZAR',
            'status' => 'processing', 'payment_status' => 'paid', 'shipping_status' => 'unfulfilled', 'inventory_committed_at' => now(), 'fulfillment_version' => 0]);
    }

    public function test_attention_cards_and_order_tabs_have_identical_filters(): void
    {
        $this->staff();
        $prepare = $this->order();
        $pending = $this->order(['payment_status' => 'pending', 'status' => 'awaiting_payment', 'inventory_committed_at' => null]);
        $review = $this->order(['status' => 'payment_received_stock_review']);
        $deliveryReview = $this->order(['status' => 'payment_received_delivery_review']);
        $shipped = $this->order(['shipping_status' => 'shipped']);
        $cancelled = $this->order(['status' => 'cancelled']);
        $notCommitted = $this->order(['inventory_committed_at' => null]);
        $page = Livewire::test(Dashboard::class);
        foreach (['to_prepare' => [$prepare], 'payment_attention' => [$pending], 'exceptions' => [$review, $deliveryReview]] as $key => $expected) {
            $task = collect($page->viewData('tasks'))->first(fn ($task) => str_contains($task['url'], 'tab='.$key));
            $this->assertNotNull($task);
            $this->assertSame(count($expected), $task['count']);
            $orders = Livewire::withQueryParams(['tab' => $key])->test(ListOrders::class)
                ->assertSet('activeTab', $key)->assertCanSeeTableRecords($expected)->assertCountTableRecords(count($expected));
            $orders->assertCanNotSeeTableRecords([$cancelled, $notCommitted]);
        }
        Livewire::test(ListOrders::class)->set('activeTab', 'in_transit')->assertCanSeeTableRecords([$shipped])->assertCountTableRecords(1);
    }

    public function test_order_search_accepts_a_displayed_hash_order_number_and_email(): void
    {
        $this->staff();
        $first = $this->order(['customer_email' => 'first@example.test']);
        $second = $this->order(['customer_email' => 'second@example.test']);
        Livewire::test(ListOrders::class)->searchTable('#'.$first->id)->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
        Livewire::test(ListOrders::class)->searchTable('second@example.test')->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first]);
    }

    public function test_setup_guide_has_actionable_links_and_does_not_change_store_state(): void
    {
        $this->staff();
        Mail::fake();
        Notification::fake();
        $settings = app(GeneralSettings::class);
        $settings->site_email = 'info@example.com';
        $settings->invoice_seller_name = null;
        $settings->invoice_seller_address = null;
        $settings->invoice_vat_status = 'unconfirmed';
        $settings->save();
        $before = $settings->toArray();
        $guide = app(StoreSetupGuide::class)->summary();
        $this->assertSame('contact', $guide['next']['id']);
        $this->assertStringContainsString('tab=contact', $guide['next']['url']);
        $this->get('/admin/store-readiness')->assertOk()->assertSee('Your store setup guide')->assertSee('Complete invoice details')
            ->assertSee('tab=business', false)->assertSee('tab=branding', false)->assertSee('Needs setup');
        $this->assertSame($before, app(GeneralSettings::class)->toArray());
        $this->assertDatabaseCount('orders', 0);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_setup_progress_reacts_to_saved_contact_and_business_settings(): void
    {
        $this->staff();
        $settings = app(GeneralSettings::class);
        $settings->site_email = 'support@example.test';
        $settings->invoice_seller_name = 'Actual legal seller';
        $settings->invoice_seller_address = '10 Real Street';
        $settings->invoice_vat_status = 'not_registered';
        $settings->site_logo_path = null;
        $settings->save();
        $guide = app(StoreSetupGuide::class)->summary();
        $this->assertSame('branding', $guide['next']['id']);
        $this->assertTrue(collect($guide['steps'])->firstWhere('id', 'contact')['complete']);
        $this->assertTrue(collect($guide['steps'])->firstWhere('id', 'business')['complete']);
        $page = Livewire::withQueryParams(['tab' => 'business'])->test(ManageGeneralSettings::class);
        $page->assertSee('data-tab-key="business"', false)->assertSee('data-tab-key="branding"', false);
    }

    public function test_restricted_staff_and_customers_do_not_see_private_setup_guide(): void
    {
        $this->staff('admin');
        Livewire::test(Dashboard::class)->assertViewHas('setupGuide', null);
        $this->get('/admin/store-readiness')->assertForbidden();
        $this->get('/admin/orders')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/admin/orders')->assertForbidden();
    }

    public function test_fulfilment_choices_match_server_rules_and_cannot_bypass_them(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        $service = app(OrderFulfillmentService::class);
        $this->assertSame(['unfulfilled' => 'Not started', 'processing' => 'Preparing'], $service->statusOptions($order));
        $blocked = $this->order(['status' => 'payment_received_delivery_review']);
        $this->assertSame(['unfulfilled' => 'Not started'], $service->statusOptions($blocked));
        $this->assertStringContainsString('Resolve delivery booking', OrderWorkspace::nextStep($blocked));
        try {
            $service->update($order, ['fulfillment_version' => 0, 'shipping_status' => 'shipped', 'delivery_carrier' => 'Local driver', 'supplier_tracking_number' => 'REF-1'], $actor);
            $this->fail('Skipping preparing must remain forbidden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('shipping_status', $exception->errors());
        }
        $this->assertSame('unfulfilled', $order->fresh()->shipping_status);
        Livewire::test(EditOrder::class, ['record' => $order->id])->assertSee('Select Preparing and save')
            ->fillForm(['shipping_status' => 'processing'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('processing', $order->fresh()->shipping_status);
    }

    public function test_order_workspace_shows_buyer_separately_and_escapes_input(): void
    {
        $this->staff();
        $order = $this->order(['recipient_name' => 'Gift recipient', 'billing_details' => ['name' => '<script>Buyer</script>', 'address' => 'Buyer address', 'vat_number' => '4123456789']]);
        Livewire::test(EditOrder::class, ['record' => $order->id])->assertSee('Buyer / invoice details')
            ->assertSee('Gift recipient')->assertSee('Buyer address')->assertSee('4123456789')->assertDontSee('<script>Buyer</script>', false);
    }
}
