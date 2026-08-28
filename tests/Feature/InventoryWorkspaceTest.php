<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Pages\Inventory;
use App\Filament\Admin\Resources\Products\Pages\EditProduct;
use App\Filament\Admin\Resources\Products\Pages\ListProducts;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\InventoryManagementService;
use App\Services\ProductVariantDraftService;
use App\Services\ProductVariantPublicationService;
use App\Support\InventoryWorkspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InventoryWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $this->actor = User::factory()->create();
        $this->actor->assignRole(Role::findOrCreate('admin', 'web'));
        foreach (['view_any_product', 'view_product', 'update_product'] as $name) {
            $this->actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function product(array $extra = []): Product
    {
        return Product::create($extra + ['name' => 'Fresh roses', 'slug' => 'roses-'.uniqid(), 'price' => 100, 'inventory_count' => 10, 'low_stock_threshold' => 3, 'pricing_type' => 'fixed', 'is_downloadable' => false])->fresh();
    }

    private function variant(Product $product, int $stock = 4): ProductVariant
    {
        $draft = app(ProductVariantDraftService::class)->save($product, null, ['version' => 0, 'sku' => 'ROSE-'.uniqid(), 'title' => 'Large bouquet', 'options' => ['Size' => 'Large'], 'price' => 150, 'archived' => false], $this->actor);

        return app(ProductVariantPublicationService::class)->publish($product, $draft, ['draft_version' => 1, 'version' => 0, 'inventory_quantity' => $stock, 'weight' => 1, 'active' => true], $this->actor);
    }

    private function hold(Product $product, int $units, ?ProductVariant $variant = null, array $extra = []): void
    {
        $order = Order::create(['customer_email' => 'buyer@example.test', 'order_date' => now(), 'total_amount' => 100, 'currency' => 'ZAR', 'payment_status' => 'pending', 'status' => 'awaiting_payment', 'shipping_status' => 'unfulfilled']);
        DB::table('stock_reservations')->insert($extra + ['product_id' => $product->id, 'order_id' => $order->id, 'variant_key' => $variant?->id ?? 0, 'quantity' => $units, 'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function change(Product $product, int $quantity, int $expected = 10, ?int $variant = null, string $mode = 'adjust')
    {
        return app(InventoryManagementService::class)->adjust($product, $variant, ['quantity' => $quantity, 'expected_quantity' => $expected, 'mode' => $mode, 'reason' => 'Expired / wilted flowers'], $this->actor);
    }

    private function invalid(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Unsafe stock update should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }
    }

    public function test_receiving_wastage_and_count_record_before_after_and_staff(): void
    {
        $product = $this->product();
        $this->change($product, 5);
        $this->change($product, -3, 15);
        $this->change($product, 8, 12, mode: 'set');
        $this->assertSame(8, $product->fresh()->inventory_count);
        $this->assertDatabaseCount('inventory_logs', 3);
        $this->assertDatabaseHas('inventory_logs', ['product_id' => $product->id, 'quantity_change' => -4, 'old_quantity' => 12, 'new_quantity' => 8, 'reference_id' => $this->actor->id, 'reference_type' => User::class]);
        $this->change($product, 8, 8, mode: 'set');
        $this->assertDatabaseCount('inventory_logs', 3);
        Mail::assertNothingSent();
    }

    public function test_stale_duplicate_negative_and_oversized_adjustments_are_rejected(): void
    {
        $product = $this->product();
        $this->change($product, 2);
        $this->invalid(fn () => $this->change($product, 2));
        $this->invalid(fn () => $this->change($product, -13, 12));
        $this->invalid(fn () => $this->change($product, 2147483647, 12));
        $this->assertSame(12, $product->fresh()->inventory_count);
        $this->assertDatabaseCount('inventory_logs', 1);
    }

    public function test_active_holds_are_protected_and_expired_released_committed_holds_excluded(): void
    {
        $product = $this->product();
        $this->hold($product, 7);
        $this->hold($product, 2, extra: ['expires_at' => now()->subMinute()]);
        $this->hold($product, 2, extra: ['released_at' => now()]);
        $this->hold($product, 2, extra: ['committed_at' => now()]);
        $this->invalid(fn () => $this->change($product, -4));
        $row = app(InventoryWorkspace::class)->query()->first();
        $this->assertSame(7, (int) $row->reserved);
        $this->assertSame(3, (int) $row->available);
        $this->change($product, -3);
        $this->assertSame(7, $product->fresh()->inventory_count);
    }

    public function test_variant_stock_is_separate_protected_and_invalidates_publication_form(): void
    {
        $product = $this->product(['inventory_count' => 999]);
        $variant = $this->variant($product);
        $this->hold($product, 3, $variant);
        $this->invalid(fn () => $this->change($product, -2, 4, $variant->id));
        $this->change($product, 2, 4, $variant->id);
        $this->assertSame(6, $variant->fresh()->inventory_quantity);
        $this->assertSame($variant->version + 1, $variant->fresh()->version);
        $this->assertSame(999, $product->fresh()->inventory_count);
        $this->invalid(fn () => $this->change($product, 1, 999));
        $rows = app(InventoryWorkspace::class)->query()->get();
        $this->assertCount(1, $rows);
        $this->assertSame($variant->id, (int) $rows->first()->variant_id);
        $this->assertSame(3, (int) $rows->first()->available);
    }

    public function test_stock_and_history_roll_back_together(): void
    {
        $product = $this->product();
        InventoryLog::creating(fn () => throw new \RuntimeException('Simulated log failure'));
        try {
            $this->change($product, 1);
            $this->fail('Audit failure must abort.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated log failure', $e->getMessage());
        }
        $this->assertSame(10, $product->fresh()->inventory_count);
        $this->assertDatabaseCount('inventory_logs', 0);
    }

    public function test_low_stock_counts_and_filters_include_options_and_reservations(): void
    {
        $simple = $this->product();
        $this->hold($simple, 8);
        $parent = $this->product(['inventory_count' => 999]);
        $this->variant($parent, 0);
        $paused = $this->variant($parent, 0);
        $paused->forceFill(['active' => false])->save();
        $workspace = app(InventoryWorkspace::class);
        $this->assertSame(2, $workspace->query(status: 'low')->count());
        $this->assertSame(1, $workspace->query(status: 'out')->count());
        $this->assertSame(1, $workspace->query(status: 'paused')->count());
        Livewire::test(Dashboard::class)->assertViewHas('lowStock', 2);
        Livewire::withQueryParams(['status' => 'low'])->test(Inventory::class)->assertViewHas('lowCount', 2)->assertSee('Large bouquet');
        $this->assertSame(0, $workspace->query(search: '%')->count());
    }

    public function test_page_and_modal_save_have_accessible_stock_details_and_history(): void
    {
        $product = $this->product();
        Livewire::withQueryParams(['product' => $product->id])->test(Inventory::class)
            ->assertSee('On hand')->assertSee('Reserved')->assertSee('Stock history')
            ->callAction('adjustStock', data: ['expected_quantity' => 10, 'mode' => 'adjust', 'quantity' => -2, 'reason_code' => 'expired', 'note' => 'Morning check'])
            ->assertHasNoActionErrors()->assertSee('Expired / wilted flowers: Morning check');
        $this->assertSame(8, $product->fresh()->inventory_count);
        $this->get('/admin/inventory')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        Livewire::test(ListProducts::class)->assertSee('Manage stock');
    }

    public function test_read_only_staff_can_view_but_cannot_adjust_and_restricted_accounts_are_blocked(): void
    {
        $product = $this->product();
        $this->actor->revokePermissionTo('update_product');
        $this->get('/admin/inventory')->assertOk();
        Livewire::withQueryParams(['product' => $product->id])->test(Inventory::class)->assertActionHidden('adjustStock');
        $this->postJson('/inventory/adjust', ['product_id' => $product->id, 'quantity_change' => 1, 'reason' => 'test'])->assertForbidden();
        $this->actor->revokePermissionTo('view_any_product');
        $this->get('/admin/inventory')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/admin/inventory')->assertForbidden();
        $this->assertSame(10, $product->fresh()->inventory_count);
    }

    public function test_parent_api_adjustment_cannot_bypass_holds_or_variant_rules(): void
    {
        $product = $this->product();
        $this->hold($product, 9);
        $this->postJson('/inventory/adjust', ['product_id' => $product->id, 'quantity_change' => -5, 'reason' => 'loss'])->assertUnprocessable();
        $parent = $this->product();
        $this->variant($parent);
        $this->postJson('/inventory/adjust', ['product_id' => $parent->id, 'quantity_change' => 3, 'reason' => 'receive'])->assertUnprocessable();
    }

    public function test_metadata_edit_does_not_overwrite_stock(): void
    {
        $product = $this->product();
        $page = Livewire::test(EditProduct::class, ['record' => $product->slug]);
        $this->change($product, -2);
        $page->fillForm(['description' => 'Updated description', 'inventory_count' => 100])->call('save')->assertHasNoFormErrors();
        $this->assertSame(8, $product->fresh()->inventory_count);
        $this->assertSame('Updated description', $product->fresh()->description);
    }

    public function test_catalogue_api_cannot_overwrite_stock_but_can_edit_metadata(): void
    {
        $product = $this->product();
        Sanctum::actingAs($this->actor, ['catalog:write']);
        $this->patchJson('/api/products/'.$product->id, ['inventory_count' => 99])->assertUnprocessable();
        $this->patchJson('/api/products/'.$product->id, ['description' => 'API metadata edit'])->assertOk();
        $this->assertSame(10, $product->fresh()->inventory_count);
        $this->assertSame('API metadata edit', $product->fresh()->description);
    }

    public function test_variant_links_keep_the_variant_query_parameter(): void
    {
        $product = $this->product();
        $variant = $this->variant($product);
        $html = $this->get('/admin/inventory')->assertOk()->getContent();
        $this->assertStringContainsString('product='.$product->id.'&amp;variant='.$variant->id, $html);
        $this->assertStringNotContainsString('product='.$product->id.'&amp;amp;variant=', $html);
    }

    public function test_export_is_private_formula_safe_and_handles_uncategorised_variants(): void
    {
        $simple = $this->product(['name' => '=HYPERLINK("bad")', 'sku' => '+malicious']);
        $parent = $this->product(['inventory_count' => 999]);
        $variant = $this->variant($parent, 4);
        $response = app(InventoryWorkspace::class)->export(app(InventoryWorkspace::class)->query());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString($variant->sku, $csv);
        $this->assertStringNotContainsString('999', $csv);
        $this->assertStringContainsString('Reserved', $csv);
    }

    public function test_history_is_escaped_and_other_products_variant_cannot_be_selected(): void
    {
        $product = $this->product();
        app(InventoryManagementService::class)->adjust($product, null, ['mode' => 'adjust', 'quantity' => 1, 'expected_quantity' => 10, 'reason' => '<script>alert(1)</script>'], $this->actor);
        $this->get('/admin/inventory?product='.$product->id)->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
        $other = $this->product();
        $variant = $this->variant($other);
        $this->get('/admin/inventory?product='.$product->id.'&variant='.$variant->id)->assertNotFound();
        $this->get('/admin/inventory?variant='.$variant->id)->assertUnprocessable();
    }
}
