<?php

namespace Tests\Feature;

use App\Console\Commands\RepairCatalogueAccess;
use App\Filament\Admin\Pages\Inventory;
use App\Models\OrderIssue;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryManagementService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SuperAdminInventoryAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_repair_restores_catalogue_without_overriding_hard_policy_denials(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $actor = User::factory()->create();
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->assertCount(0, $actor->getAllPermissions());
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->assertFalse(Gate::forUser($actor)->allows('viewAny', Product::class));
        $this->artisan('commerce:repair-catalogue-access')->assertSuccessful();
        $this->assertCount(0, $actor->getAllPermissions());
        $this->artisan('commerce:repair-catalogue-access --apply')->assertSuccessful();
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        // No test-only gate override: the real policy must check explicit permissions.
        $this->assertTrue(Gate::forUser($actor)->allows('viewAny', Product::class));
        $this->assertTrue(Inventory::canAccess());
        $this->get('/admin/products')->assertOk();
        $this->get('/admin/inventory')->assertOk();
        $product = Product::create(['name' => 'Super-admin test flowers', 'slug' => 'super-admin-test', 'price' => 100, 'inventory_count' => 5, 'low_stock_threshold' => 2]);
        Livewire::withQueryParams(['product' => $product->id])->test(Inventory::class)->assertActionVisible('adjustStock');
        app(InventoryManagementService::class)->adjust($product, null, ['mode' => 'adjust', 'quantity' => 1, 'expected_quantity' => 5, 'reason' => 'Stock received'], $actor);
        $this->assertSame(6, $product->fresh()->inventory_count);
        $this->assertFalse(Gate::forUser($actor)->allows('delete', new OrderIssue));
        $this->assertFalse(Gate::forUser($actor)->allows('deleteAny', OrderIssue::class));
        $this->artisan('commerce:repair-catalogue-access --apply')->assertSuccessful();
        $this->assertEqualsCanonicalizing(RepairCatalogueAccess::PERMISSIONS, $actor->fresh()->getAllPermissions()->pluck('name')->all());
    }

    public function test_ordinary_admin_and_customer_do_not_inherit_super_admin_access(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $staff = User::factory()->create();
        $staff->assignRole(Role::findOrCreate('admin', 'web'));
        Role::findOrCreate('super_admin', 'web');
        $this->artisan('commerce:repair-catalogue-access --apply')->assertSuccessful();
        $this->actingAs($staff);
        $this->assertFalse(Gate::forUser($staff)->allows('viewAny', Product::class));
        $this->get('/admin/inventory')->assertForbidden();
        $this->get('/admin/products')->assertForbidden();
        $customer = User::factory()->create();
        $this->actingAs($customer)->get('/admin/inventory')->assertForbidden();
        $this->assertFalse(Gate::forUser($customer)->allows('update', new Product));
    }

    public function test_repair_never_creates_a_missing_super_admin_role(): void
    {
        $this->artisan('commerce:repair-catalogue-access --apply')->assertFailed();
        $this->assertDatabaseCount('roles', 0);
        $this->assertDatabaseCount('permissions', 0);
    }
}
