<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\ProductVariantDrafts;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariantDraft;
use App\Models\ProductVariantDraftChange;
use App\Models\User;
use App\Services\CartService;
use App\Services\ProductVariantDraftService;
use App\Support\StoreMoney;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductVariantDraftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::preventStrayRequests();
    }

    private function staff(bool $edit = true): User
    {
        $user = User::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $user->assignRole(Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']));
        foreach (['view_any_product', 'view_product', ...($edit ? ['update_product'] : [])] as $name) {
            $user->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    private function product(array $extra = []): Product
    {
        $category = ProductCategory::create(['name' => 'Draft flowers', 'slug' => 'draft-'.uniqid()]);

        return Product::create(array_merge(['name' => 'Standard bouquet', 'slug' => 'draft-bouquet-'.uniqid(),
            'price' => 125, 'inventory_count' => 10, 'category_id' => $category->id,
            'pricing_type' => 'fixed', 'is_downloadable' => false], $extra))->fresh();
    }

    private function data(array $extra = []): array
    {
        return array_merge(['version' => 0, 'sku' => 'BOUQUET-LARGE-PINK', 'title' => 'Large pink bouquet',
            'options' => ['Size' => 'Large', 'Colour' => 'Pink'], 'price' => '175.50', 'archived' => false], $extra);
    }

    private function invalid(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected validation rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_create_owns_identity_currency_and_actor_and_leaves_live_catalogue_untouched(): void
    {
        $actor = $this->staff();
        $product = $this->product();
        $before = $product->getAttributes();
        $draft = app(ProductVariantDraftService::class)->save($product, null, $this->data([
            'sku' => ' bouquet-large-pink ', 'product_id' => 999, 'actor_id' => 999,
            'currency' => 'USD', 'inventory_count' => 900, 'published' => true,
        ]), $actor);
        $this->assertSame($product->id, $draft->product_id);
        $this->assertSame('BOUQUET-LARGE-PINK', $draft->sku);
        $this->assertSame(StoreMoney::currency(), $draft->currency);
        $this->assertSame('175.50', $draft->price);
        $this->assertSame(1, $draft->version);
        $change = $draft->changes()->firstOrFail();
        $this->assertSame($actor->id, $change->actor_id);
        $this->assertNull($change->before_values);
        $this->assertArrayNotHasKey('inventory_count', $change->after_values);
        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertDatabaseCount('product_variants', 0);
        $this->assertDatabaseCount('orders', 0);
        $cart = app(CartService::class)->hydrate([$product->id => ['quantity' => 1, 'price' => 0.01]]);
        $this->assertEquals(125, $cart[$product->id]['price']);
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_no_op_edit_archive_and_restore_retain_ordered_revisions(): void
    {
        $actor = $this->staff();
        $product = $this->product();
        $service = app(ProductVariantDraftService::class);
        $draft = $service->save($product, null, $this->data(), $actor);
        $same = $service->save($product, $draft, $this->data(['version' => 1]), $actor);
        $this->assertSame(1, $same->version);
        $this->assertDatabaseCount('product_variant_draft_changes', 1);
        $edited = $service->save($product, $draft, $this->data(['version' => 1, 'price' => '190.00', 'archived' => true]), $actor);
        $this->assertSame(2, $edited->version);
        $this->assertTrue($edited->archived);
        $this->assertSame('175.50', $edited->changes()->where('version', 2)->firstOrFail()->before_values['price']);
        $restored = $service->save($product, $edited, $this->data(['version' => 2, 'price' => '190.00']), $actor);
        $this->assertFalse($restored->archived);
        $this->assertSame(3, $restored->version);
        $this->assertDatabaseCount('product_variant_draft_changes', 3);
    }

    public function test_stale_edit_is_rejected_without_another_revision(): void
    {
        $actor = $this->staff();
        $product = $this->product();
        $service = app(ProductVariantDraftService::class);
        $draft = $service->save($product, null, $this->data(), $actor);
        $this->invalid(fn () => $service->save($product, $draft, $this->data(['price' => '1.00']), $actor), 'title');
        $this->assertSame('175.50', $draft->fresh()->price);
        $this->assertDatabaseCount('product_variant_draft_changes', 1);
    }

    public function test_archived_sku_is_reserved_across_products(): void
    {
        $actor = $this->staff();
        $service = app(ProductVariantDraftService::class);
        $service->save($this->product(), null, $this->data(['archived' => true]), $actor);
        $this->invalid(fn () => $service->save($this->product(), null, $this->data(['sku' => 'bouquet-large-pink']), $actor), 'sku');
        $this->assertDatabaseCount('product_variant_drafts', 1);
    }

    public function test_invalid_fields_and_nested_option_errors_are_rejected_on_visible_fields(): void
    {
        $actor = $this->staff();
        $product = $this->product();
        $cases = [
            ['sku', 'bad sku'], ['sku', str_repeat('A', 65)], ['title', '   '],
            ['price', '-1'], ['price', '1.001'], ['price', '1e2'], ['price', '100000000'],
            ['options', []], ['options', ['Size' => ['Large']]],
            ['options', ['Size' => 'Large', ' size ' => 'Small']],
            ['options', ['Size' => str_repeat('x', 81)]],
            ['options', ['Bad.key' => 'Large']], ['options', ['Size' => '  ']],
            ['options', ['One' => '1', 'Two' => '2', 'Three' => '3', 'Four' => '4']],
            ['version', -1], ['archived', 'yes'],
        ];
        foreach ($cases as [$field, $value]) {
            $this->invalid(fn () => app(ProductVariantDraftService::class)->save($product, null, $this->data([$field => $value]), $actor), $field);
        }
        $this->assertDatabaseCount('product_variant_drafts', 0);
    }

    public function test_read_only_staff_can_view_but_cannot_edit(): void
    {
        $actor = $this->staff(false);
        $product = $this->product();
        Livewire::withQueryParams(['product' => $product->id])->test(ProductVariantDrafts::class)
            ->assertSuccessful()->assertActionHidden('saveDraft');
        $this->expectException(AuthorizationException::class);
        app(ProductVariantDraftService::class)->save($product, null, $this->data(), $actor);
    }

    public function test_customer_cannot_open_admin_drafts(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/admin/product-variant-drafts')->assertForbidden();
    }

    public function test_wrong_parent_is_rejected_by_service_and_page(): void
    {
        $actor = $this->staff();
        $service = app(ProductVariantDraftService::class);
        $draft = $service->save($this->product(), null, $this->data(), $actor);
        $other = $this->product();
        $this->get('/admin/product-variant-drafts?product='.$other->id.'&draft='.$draft->id)->assertNotFound();
        $this->expectException(ModelNotFoundException::class);
        $service->save($other, $draft, $this->data(['version' => 1]), $actor);
    }

    public function test_parent_reference_cannot_be_changed_in_livewire(): void
    {
        $this->staff();
        $product = $this->product();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::withQueryParams(['product' => $product->id])->test(ProductVariantDrafts::class)->set('productId', 999);
    }

    public function test_action_creates_then_edits_and_maps_validation_to_form(): void
    {
        $this->staff();
        $product = $this->product();
        Livewire::withQueryParams(['product' => $product->id])->test(ProductVariantDrafts::class)
            ->callAction('saveDraft', data: $this->data(['options' => [['key' => 'Size', 'value' => 'Large']]]))
            ->assertHasNoActionErrors();
        $draft = ProductVariantDraft::sole();
        $this->assertSame(['Size' => 'Large'], $draft->options);
        $editor = Livewire::withQueryParams(['product' => $product->id, 'draft' => $draft->id])->test(ProductVariantDrafts::class)
            ->assertSet('draftId', $draft->id)
            ->mountAction('saveDraft')
            ->setActionData($this->data(['version' => 1, 'options' => [['key' => 'Size', 'value' => str_repeat('x', 81)]]]));
        $editor->callMountedAction()->assertHasActionErrors(['options']);
        Livewire::withQueryParams(['product' => $product->id, 'draft' => $draft->id])->test(ProductVariantDrafts::class)
            ->callAction('saveDraft', data: ['version' => 1, 'archived' => true])
            ->assertHasNoActionErrors()->assertSee('Archived draft');
        $this->assertTrue($draft->fresh()->archived);
    }

    public function test_catalogue_filters_and_escaped_history_are_private(): void
    {
        $actor = $this->staff();
        $product = $this->product();
        $service = app(ProductVariantDraftService::class);
        $draft = $service->save($product, null, $this->data(['title' => '<script>private-draft</script>', 'archived' => true]), $actor);
        $base = '/admin/product-variant-drafts?product='.$product->id;
        $this->get($base.'&search=')->assertOk();
        $this->get($base)->assertOk()->assertDontSee('BOUQUET-LARGE-PINK');
        $this->get($base.'&status=archived&search=BOUQUET')->assertOk()->assertSee('BOUQUET-LARGE-PINK');
        $this->get($base.'&status=all&search=%25')->assertOk()->assertDontSee('BOUQUET-LARGE-PINK');
        $this->get($base.'&draft='.$draft->id)->assertOk()->assertSee('&lt;script&gt;private-draft&lt;/script&gt;', false)
            ->assertDontSee('<script>private-draft</script>', false)->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->get($base.'&status=invalid')->assertStatus(422);
    }

    public function test_digital_and_non_fixed_products_cannot_receive_drafts(): void
    {
        $actor = $this->staff();
        foreach ([['is_downloadable' => true], ['pricing_type' => 'subscription']] as $attributes) {
            $product = $this->product($attributes);
            $this->invalid(fn () => app(ProductVariantDraftService::class)->save($product, null, $this->data(), $actor), 'title');
        }
        $this->assertDatabaseCount('product_variant_drafts', 0);
    }

    public function test_audit_failure_rolls_back_the_draft(): void
    {
        $actor = $this->staff();
        $product = $this->product();
        ProductVariantDraftChange::creating(fn () => throw new \RuntimeException('Simulated audit failure'));
        try {
            app(ProductVariantDraftService::class)->save($product, null, $this->data(), $actor);
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated audit failure', $exception->getMessage());
        } finally {
            ProductVariantDraftChange::flushEventListeners();
        }
        $this->assertDatabaseCount('product_variant_drafts', 0);
        $this->assertDatabaseCount('product_variant_draft_changes', 0);
    }

    public function test_currency_change_cannot_silently_reprice_an_existing_draft(): void
    {
        $actor = $this->staff();
        $product = $this->product();
        $service = app(ProductVariantDraftService::class);
        config(['commerce.currency' => 'ZAR']);
        $draft = $service->save($product, null, $this->data(), $actor);
        config(['commerce.currency' => 'USD']);
        $this->invalid(fn () => $service->save($product, $draft, $this->data(['version' => 1]), $actor), 'price');
        $this->assertSame('ZAR', $draft->fresh()->currency);
        $this->assertDatabaseCount('product_variant_draft_changes', 1);
    }

    public function test_revoked_update_permission_blocks_an_open_editor(): void
    {
        $actor = $this->staff();
        $product = $this->product();
        $component = Livewire::withQueryParams(['product' => $product->id])->test(ProductVariantDrafts::class)
            ->mountAction('saveDraft');
        $actor->revokePermissionTo('update_product');
        $actor->unsetRelation('permissions');
        $component->setActionData($this->data())->callMountedAction();
        $this->assertDatabaseCount('product_variant_drafts', 0);
    }
}
