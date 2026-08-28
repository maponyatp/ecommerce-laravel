<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\StoreReadinessService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StoreReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_reports_configuration_without_claiming_certification_or_exposing_secrets(): void
    {
        config(['app.url' => 'https://store.example.test', 'app.debug' => false,
            'services.ikhokha.app_id' => 'PRIVATE-APP', 'services.ikhokha.app_secret' => 'PRIVATE-SECRET']);
        $settings = app(GeneralSettings::class);
        $settings->invoice_seller_name = 'Seller';
        $settings->invoice_seller_address = 'Business address';
        $settings->invoice_vat_status = 'not_registered';
        $settings->site_email = 'support@example.test';
        $settings->save();
        $report = app(StoreReadinessService::class)->report();
        $this->assertSame(0, $report['blocked_count']);
        $this->assertFalse($report['production_certified']);
        $this->assertNotEmpty($report['manual_reviews']);
        $this->assertStringNotContainsString('PRIVATE-', json_encode($report));
        $this->artisan('commerce:preflight', ['--json' => true])->expectsOutputToContain('"production_certified": false')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_preflight_flags_missing_payment_and_delivery_setup_for_physical_products(): void
    {
        config(['app.url' => 'http://store.example.test', 'app.debug' => true, 'services.ikhokha.app_id' => null,
            'services.ikhokha.app_secret' => null, 'services.stripe.key' => null, 'services.stripe.secret' => null]);
        $category = ProductCategory::create(['name' => 'Goods', 'slug' => 'goods']);
        Product::create(['name' => 'Goods', 'slug' => 'goods', 'price' => 10, 'inventory_count' => 1, 'category_id' => $category->id, 'is_downloadable' => false]);
        $checks = collect(app(StoreReadinessService::class)->report()['checks'])->keyBy('id');
        foreach (['https', 'debug', 'payments', 'delivery', 'seller'] as $id) {
            $this->assertFalse($checks[$id]['passed']);
        }
        $this->artisan('commerce:preflight')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_launch_checks_page_is_private_and_super_admin_only(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user)->get('/admin/store-readiness')->assertForbidden();
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->current_team_id);
        $user->assignRole(Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']));
        $this->get('/admin/store-readiness')->assertForbidden();
        $user->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
        $response = $this->get('/admin/store-readiness')->assertOk()->assertSee('Launch checks')->assertSee('Manual acceptance checks');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('store_integrations', 0);
    }
}
