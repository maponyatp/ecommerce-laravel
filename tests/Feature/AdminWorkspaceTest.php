<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Pages\ManageGeneralSettings;
use App\Filament\Admin\Pages\StoreIntegrations;
use App\Models\Order;
use App\Models\StoreIntegration;
use App\Models\User;
use App\Services\Payments\IkhokhaGateway;
use App\Services\StoreIntegrationService;
use App\Settings\GeneralSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminWorkspaceTest extends TestCase
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

    public function test_dashboard_has_consistent_navigation_actions_and_honest_setup_state(): void
    {
        $this->staff();
        config(['services.ikhokha.app_id' => '', 'services.ikhokha.app_secret' => '', 'services.stripe.key' => '', 'services.stripe.secret' => '']);
        $this->get('/admin')->assertOk()->assertSee('Store overview')->assertSee('What needs attention')
            ->assertSee('Setup needed before accepting paid orders.')->assertSee('Customise store')
            ->assertSee('Online store')->assertSee('Catalogue')->assertSee('Payments &amp; delivery', false)
            ->assertDontSee('Active Chats')->assertDontSee('Total Revenue')
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private');
        Livewire::test(Dashboard::class)->assertActionVisible('addProduct');
        $navigation = collect(Filament::getCurrentPanel()->getNavigation())
            ->mapWithKeys(fn ($group) => [($group->getLabel() ?: 'root') => collect($group->getItems())->map->getLabel()->all()]);
        $this->assertContains('Overview', $navigation['root']);
        $this->assertContains('Products', $navigation['Catalogue']);
        $this->assertContains('Pages', $navigation['Online store']);
        $this->assertContains('Payments & delivery', $navigation['Settings']);
        $this->assertNotContains('Overview', $navigation['Marketing & reports']);
    }

    public function test_dashboard_totals_exclude_unpaid_and_foreign_currency_orders(): void
    {
        $this->staff();
        foreach ([['paid', 'ZAR', 120], ['pending', 'ZAR', 9000], ['paid', 'USD', 7500]] as [$status, $currency, $amount]) {
            Order::create(['order_date' => now(), 'customer_email' => 'customer@example.test', 'currency' => $currency,
                'total_amount' => $amount, 'payment_status' => $status, 'status' => 'pending', 'shipping_status' => 'unfulfilled']);
        }
        $page = Livewire::test(Dashboard::class);
        $this->assertSame(120.0, $page->viewData('metrics')['total_revenue']);
        $this->assertSame(1, $page->viewData('metrics')['order_count']);
        $this->assertSame(1, $page->viewData('metrics')['excluded_currency_orders']);
    }

    public function test_restricted_staff_cannot_see_order_data_or_integration_settings(): void
    {
        $this->staff('admin');
        Order::create(['order_date' => now(), 'customer_email' => 'private-buyer@example.test', 'total_amount' => 120, 'currency' => 'ZAR', 'payment_status' => 'pending', 'shipping_status' => 'unfulfilled']);
        $this->get('/admin')->assertOk()->assertDontSee('private-buyer@example.test')->assertDontSee('Paid order totals');
        $this->get('/admin/store-integrations')->assertForbidden();
        Livewire::test(Dashboard::class)->assertActionHidden('addProduct');
    }

    public function test_customers_cannot_open_the_admin_workspace_or_integrations(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/admin')->assertForbidden();
        $this->get('/admin/store-integrations')->assertForbidden();
    }

    public function test_credentials_are_encrypted_and_gateway_uses_saved_details(): void
    {
        $actor = $this->staff();
        app(StoreIntegrationService::class)->save('ikhokha', ['app_id' => 'merchant-id', 'app_secret' => 'merchant-secret', 'enabled' => true], 0, $actor);
        $raw = DB::table('store_integrations')->where('provider', 'ikhokha')->value('credentials');
        $this->assertStringNotContainsString('merchant-secret', $raw);
        $this->assertStringNotContainsString('merchant-id', $raw);
        $this->assertStringNotContainsString('merchant-secret', StoreIntegration::first()->toJson());
        $this->assertSame('merchant-secret', app(StoreIntegrationService::class)->paymentConfiguration()['app_secret']);
        $this->assertTrue(app(IkhokhaGateway::class)->isConfigured());
        $this->assertStringNotContainsString('merchant-secret', json_encode(DB::table('store_integration_changes')->get()));
        Http::assertNothingSent();
    }

    public function test_admin_can_save_both_forms_without_disclosing_saved_values(): void
    {
        $this->staff();
        $page = Livewire::test(StoreIntegrations::class)->assertOk()
            ->set('dsvData.client_id', 'unsaved-client')
            ->set('ikhokhaData.app_id', 'ui-merchant-id')->set('ikhokhaData.app_secret', 'ui-secret-123')
            ->set('ikhokhaData.enabled', true)->call('saveIkhokha')->assertHasNoErrors()
            ->assertSet('ikhokhaData.app_secret', '')->assertSet('dsvData.client_id', 'unsaved-client');
        $this->assertTrue(app(IkhokhaGateway::class)->isConfigured());
        $page->set('dsvData.client_secret', 'courier-secret')->call('saveDsv')->assertHasNoErrors()
            ->assertSet('dsvData.client_secret', '')->assertSee('Settings saved — integration pending');
        $this->assertSame('courier-secret', StoreIntegration::find('dsv')->credentials['client_secret']);
        Livewire::test(StoreIntegrations::class)->assertSet('ikhokhaData.app_id', '')->assertSet('ikhokhaData.app_secret', '')
            ->assertSet('dsvData.client_secret', '')->assertDontSee('ui-secret-123')->assertDontSee('courier-secret');
        Http::assertNothingSent();
    }

    public function test_blank_fields_keep_credentials_and_explicit_disable_and_removal_are_supported(): void
    {
        $actor = $this->staff();
        $service = app(StoreIntegrationService::class);
        $service->save('ikhokha', ['app_id' => 'id', 'app_secret' => 'secret', 'enabled' => true], 0, $actor);
        $service->save('ikhokha', ['app_id' => '', 'app_secret' => '', 'enabled' => true], 1, $actor);
        $this->assertTrue(app(IkhokhaGateway::class)->isConfigured());
        $service->save('ikhokha', ['clear_credentials' => true, 'enabled' => false], 2, $actor);
        $this->assertFalse(app(IkhokhaGateway::class)->isConfigured());
        $this->assertSame([], StoreIntegration::find('ikhokha')->credentials);
    }

    public function test_incomplete_and_stale_credentials_are_rejected_atomically(): void
    {
        $actor = $this->staff();
        $service = app(StoreIntegrationService::class);
        try {
            $service->save('ikhokha', ['app_id' => 'id', 'enabled' => true], 0, $actor);
            $this->fail('Expected missing credential rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('settings', $exception->errors());
        }
        $this->assertDatabaseCount('store_integrations', 0);
        $service->save('ikhokha', ['app_id' => 'id', 'app_secret' => 'secret', 'enabled' => true], 0, $actor);
        try {
            $service->save('ikhokha', ['app_id' => 'different', 'app_secret' => 'different-secret', 'enabled' => true], 0, $actor);
            $this->fail('Expected stale edit rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('settings', $exception->errors());
        }
        $this->assertSame('id', StoreIntegration::find('ikhokha')->credentials['app_id']);
        $this->assertDatabaseCount('store_integration_changes', 1);
    }

    public function test_pending_payments_block_credential_rotation_and_gateway_disabling(): void
    {
        $actor = $this->staff();
        $service = app(StoreIntegrationService::class);
        $service->save('ikhokha', ['app_id' => 'id', 'app_secret' => 'secret', 'enabled' => true], 0, $actor);
        $order = Order::create(['order_date' => now(), 'total_amount' => 100, 'currency' => 'ZAR', 'payment_status' => 'pending', 'shipping_status' => 'unfulfilled']);
        $order->paymentTransactions()->create(['gateway' => 'ikhokha', 'external_transaction_id' => 'pending', 'amount' => 100, 'currency' => 'ZAR', 'status' => 'pending']);
        try {
            $service->save('ikhokha', ['enabled' => false], 1, $actor);
            $this->fail('Expected pending-payment protection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('settings', $exception->errors());
        }
        $this->assertTrue(app(IkhokhaGateway::class)->isConfigured());
    }

    public function test_non_super_admin_cannot_write_credentials_through_service(): void
    {
        $actor = $this->staff('admin');
        try {
            app(StoreIntegrationService::class)->save('ikhokha', ['enabled' => false], 0, $actor);
            $this->fail('Expected permission denial.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('store_integrations', 0);
    }

    public function test_saved_account_credentials_sign_gateway_requests_without_using_environment_account(): void
    {
        $actor = $this->staff();
        config(['services.ikhokha.app_id' => 'old-account', 'services.ikhokha.app_secret' => 'old-secret']);
        app(StoreIntegrationService::class)->save('ikhokha', ['app_id' => 'new-account', 'app_secret' => 'new-secret', 'enabled' => true], 0, $actor);
        Http::fake(['*' => Http::response(['paylinkID' => 'link-1', 'amount' => 10000, 'status' => 'PAID'])]);
        app(IkhokhaGateway::class)->paymentStatus('link-1');
        Http::assertSent(fn ($request) => $request->hasHeader('IK-APPID', 'new-account')
            && $request->hasHeader('IK-SIGN', hash_hmac('sha256', '/public-api/v1/api/getStatus/link-1', 'new-secret')));
    }

    public function test_corrupt_stored_credentials_fail_closed_without_falling_back_to_environment(): void
    {
        $actor = $this->staff();
        config(['services.ikhokha.app_id' => 'old-account', 'services.ikhokha.app_secret' => 'old-secret']);
        app(StoreIntegrationService::class)->save('ikhokha', ['app_id' => 'new-account', 'app_secret' => 'new-secret', 'enabled' => true], 0, $actor);
        DB::table('store_integrations')->where('provider', 'ikhokha')->update(['credentials' => 'invalid-ciphertext']);
        $this->assertFalse(app(IkhokhaGateway::class)->isConfigured());
        Http::assertNothingSent();
    }

    public function test_tabbed_store_settings_save_without_losing_other_homepage_fields(): void
    {
        $this->staff();
        $hero = app(GeneralSettings::class)->hero_title;
        Livewire::test(ManageGeneralSettings::class)->assertOk()
            ->assertSee('Brand &amp; theme', false)->assertSee('Homepage')->assertSee('Search &amp; social', false)
            ->set('data.site_name', 'Modern Floral Test')->set('data.store_primary_color', '#5145b5')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('Modern Floral Test', app(GeneralSettings::class)->site_name);
        $this->assertSame($hero, app(GeneralSettings::class)->hero_title);
    }
}
