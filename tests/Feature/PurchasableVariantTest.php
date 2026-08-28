<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\ProductVariantDrafts;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\ProductVariantDraft;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\CartService;
use App\Services\OrderPaymentService;
use App\Services\ProductVariantDraftService;
use App\Services\ProductVariantPublicationService;
use App\Services\StockReservationService;
use App\Support\StoreMoney;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PurchasableVariantTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::preventStrayRequests();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $this->actor = User::factory()->create();
        $this->actor->assignRole(Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']));
        foreach (['view_any_product', 'view_product', 'update_product'] as $name) {
            $this->actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function product(): Product
    {
        $category = ProductCategory::create(['name' => 'Options', 'slug' => 'options-'.uniqid()]);

        return Product::create(['name' => 'Rose bouquet', 'slug' => 'bouquet-'.uniqid(), 'category_id' => $category->id,
            'price' => 10, 'inventory_count' => 99, 'pricing_type' => 'fixed', 'is_downloadable' => false])->fresh();
    }

    private function draft(Product $product, string $size = 'Large', string $price = '175.50'): ProductVariantDraft
    {
        return app(ProductVariantDraftService::class)->save($product, null, ['version' => 0,
            'sku' => strtoupper($size).'-'.uniqid(), 'title' => $size.' roses', 'options' => ['Size' => $size], 'price' => $price, 'archived' => false], $this->actor);
    }

    private function publish(Product $product, ProductVariantDraft $draft, int $stock = 3, array $extra = []): ProductVariant
    {
        $live = ProductVariant::where('draft_id', $draft->id)->first();

        return app(ProductVariantPublicationService::class)->publish($product, $draft, array_merge([
            'draft_version' => $draft->version, 'version' => $live?->version ?? 0,
            'inventory_quantity' => $stock, 'weight' => '1.25', 'active' => true,
        ], $extra), $this->actor);
    }

    private function invalid(callable $operation, string $field = 'cart'): void
    {
        try {
            $operation();
            $this->fail('Expected validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function hold(Product $product, array $variants): Order
    {
        return DB::transaction(function () use ($product, $variants) {
            $order = Order::create(['customer_email' => 'buyer@example.test', 'order_date' => now(), 'currency' => 'ZAR',
                'total_amount' => 0, 'payment_status' => 'pending', 'payment_method' => 'ikhokha', 'status' => 'pending', 'shipping_status' => 'unfulfilled']);
            $total = 0;
            foreach ($variants as [$variant, $quantity]) {
                $line = app(CartService::class)->line($variant ? "v:$product->id:$variant->id" : $product->id, $quantity);
                $order->items()->create(['product_id' => $product->id, 'product_variant_id' => $variant?->id,
                    'price' => $line['price'], 'quantity' => $quantity, 'product_name_snapshot' => $line['name'],
                    'sku_snapshot' => $line['sku_snapshot'] ?? null, 'options_snapshot' => $line['options_snapshot'] ?? null, 'is_downloadable_snapshot' => false]);
                $total += $line['price'] * $quantity;
            }
            $order->update(['total_amount' => $total]);
            app(StockReservationService::class)->reserve($order);

            return $order->fresh();
        });
    }

    private function payment(Order $order): PaymentTransaction
    {
        return PaymentTransaction::create(['order_id' => $order->id, 'gateway' => 'ikhokha', 'external_transaction_id' => 'TEST-'.uniqid(),
            'amount' => $order->total_amount, 'currency' => 'ZAR', 'status' => 'pending']);
    }

    public function test_publish_owns_identity_and_records_audit_without_changing_parent_stock(): void
    {
        $product = $this->product();
        $draft = $this->draft($product);
        $variant = $this->publish($product, $draft, 4, ['product_id' => 999, 'currency' => 'USD', 'actor_id' => 999]);
        $this->assertTrue($product->fresh()->has_variants);
        $this->assertSame(99, $product->fresh()->inventory_count);
        $this->assertSame('10.00', $product->fresh()->price);
        $this->assertSame($product->id, $variant->product_id);
        $this->assertSame('ZAR', $variant->currency);
        $this->assertTrue($variant->active);
        $this->assertDatabaseHas('product_variant_publications', ['actor_id' => $this->actor->id, 'product_variant_id' => $variant->id]);
        $this->assertDatabaseHas('inventory_logs', ['product_variant_id' => $variant->id, 'quantity_change' => 4]);
        Http::assertNothingSent();
    }

    public function test_http_selection_and_cart_rehydrate_reject_forged_metadata_and_parent_price(): void
    {
        $product = $this->product();
        $variant = $this->publish($product, $this->draft($product));
        $key = "v:$product->id:$variant->id";
        $this->post(route('cart.add', $product), ['variant_id' => $variant->id, 'price' => 0.01, 'quantity' => 2])->assertSessionHas('success');
        $this->assertEquals('175.50', session('cart')[$key]['price']);
        $cart = app(CartService::class)->hydrate([$key => ['quantity' => 1, 'product_variant_id' => 999, 'price' => 1, 'is_downloadable' => true, 'weight' => -100]]);
        $this->assertSame($variant->id, $cart[$key]['product_variant_id']);
        $this->assertSame('175.50', $cart[$key]['price']);
        $this->assertSame(1.25, $cart[$key]['weight']);
        $this->assertFalse($cart[$key]['is_downloadable']);
        $this->invalid(fn () => app(CartService::class)->add($product->id));
        $this->invalid(fn () => app(CartService::class)->add($product->id, 1, ['malformed']));
        $this->invalid(fn () => app(CartService::class)->hydrate(['v:01:1' => ['quantity' => 1]]));
        $this->invalid(fn () => app(CartService::class)->add($this->product()->id, 1, $variant->id));
        app(CartService::class)->update($key, 1);
        $this->assertSame(1, session('cart')[$key]['quantity']);
        app(CartService::class)->remove($key);
        $this->assertSame([], session('cart'));
    }

    public function test_multiple_variants_of_one_product_have_independent_reservations(): void
    {
        $product = $this->product();
        $large = $this->publish($product, $this->draft($product), 2);
        $small = $this->publish($product, $this->draft($product, 'Small', '99.00'), 5);
        $order = $this->hold($product, [[$large, 2], [$small, 1]]);
        $this->assertSame(0, app(StockReservationService::class)->available($product, variant: $large));
        $this->assertSame(4, app(StockReservationService::class)->available($product, variant: $small));
        $this->assertDatabaseCount('stock_reservations', 2);
        $this->invalid(fn () => $this->hold($product, [[$large, 1]]));
        $this->assertSame(1, Order::count());
        $transaction = $this->payment($order);
        app(OrderPaymentService::class)->markPaid($transaction);
        app(OrderPaymentService::class)->markPaid($transaction);
        $this->assertSame(0, $large->fresh()->inventory_quantity);
        $this->assertSame(4, $small->fresh()->inventory_quantity);
        $this->assertSame(99, $product->fresh()->inventory_count);
        $this->assertSame(2, InventoryLog::where('reason', 'order')->count());
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_publication_detects_active_holds_and_stale_stock(): void
    {
        $product = $this->product();
        $draft = $this->draft($product);
        $variant = $this->publish($product, $draft, 3);
        $order = $this->hold($product, [[$variant, 2]]);
        $this->invalid(fn () => $this->publish($product, $draft, 1), 'inventory_quantity');
        app(OrderPaymentService::class)->markPaid($this->payment($order));
        $this->invalid(fn () => $this->publish($product, $draft, 3, ['version' => $variant->version]), 'inventory_quantity');
        $this->assertSame(1, $variant->fresh()->inventory_quantity);
        $this->assertDatabaseCount('product_variant_publications', 1);
    }

    public function test_cannot_convert_a_product_with_an_active_simple_checkout(): void
    {
        $product = $this->product();
        $this->hold($product, [[null, 1]]);
        $this->invalid(fn () => $this->publish($product, $this->draft($product)), 'inventory_quantity');
        $this->assertFalse($product->fresh()->has_variants);
        $this->assertDatabaseCount('product_variants', 0);
    }

    public function test_deactivation_blocks_new_carts_but_preserves_payment_and_invoice_identity(): void
    {
        $product = $this->product();
        $draft = $this->draft($product);
        $variant = $this->publish($product, $draft);
        app(CartService::class)->add($product->id, 1, $variant->id);
        $order = $this->hold($product, [[$variant, 1]]);
        $draft = app(ProductVariantDraftService::class)->save($product, $draft,
            [...app(ProductVariantDraftService::class)->values($draft), 'version' => $draft->version, 'title' => 'New title', 'price' => '200.00'], $this->actor);
        $this->assertSame('175.50', $variant->fresh()->price);
        $this->publish($product, $draft, 3, ['active' => false]);
        $this->invalid(fn () => app(CartService::class)->hydrate(session('cart')));
        $this->assertNotEmpty(app(CartService::class)->snapshot()['errors']);
        app(OrderPaymentService::class)->markPaid($this->payment($order));
        $this->assertSame('committed', $order->fresh()->stock_reservation_status);
        $invoice = $order->invoice()->firstOrFail();
        $this->assertSame('175.50', $invoice->document_snapshot['items'][0]['unit_price']);
        $this->assertSame($variant->sku, $invoice->document_snapshot['items'][0]['sku']);
        $this->assertStringContainsString('Large roses', $invoice->document_snapshot['items'][0]['name']);
        $this->assertStringNotContainsString('New title', $invoice->document_snapshot['items'][0]['name']);
    }

    public function test_late_payment_does_not_take_another_customers_variant_hold(): void
    {
        $product = $this->product();
        $variant = $this->publish($product, $this->draft($product), 1);
        $late = $this->hold($product, [[$variant, 1]]);
        $this->travel(31)->minutes();
        app(StockReservationService::class)->expire();
        $current = $this->hold($product, [[$variant, 1]]);
        app(OrderPaymentService::class)->markPaid($this->payment($late));
        $this->assertSame('payment_received_stock_review', $late->fresh()->status);
        $this->assertSame(1, $variant->fresh()->inventory_quantity);
        $this->assertSame('held', $current->fresh()->stock_reservation_status);
        $this->travelBack();
    }

    public function test_unauthorized_staff_cannot_publish(): void
    {
        $product = $this->product();
        $draft = $this->draft($product);
        $this->actor->revokePermissionTo('update_product');
        $this->actor->unsetRelation('permissions');
        try {
            $this->publish($product, $draft);
            $this->fail('Expected permission rejection.');
        } catch (AuthorizationException $exception) {
            $this->assertDatabaseCount('product_variants', 0);
        }
    }

    public function test_published_identity_is_fixed_and_invalid_types_are_blocked(): void
    {
        $product = $this->product();
        $draft = $this->draft($product);
        $variant = $this->publish($product, $draft);
        $this->invalid(fn () => app(ProductVariantDraftService::class)->save($product, $draft,
            [...app(ProductVariantDraftService::class)->values($draft), 'version' => $draft->version, 'options' => ['Size' => 'XL']], $this->actor), 'sku');
        $this->invalid(fn () => $product->fresh()->update(['is_downloadable' => true]), 'pricing_type');
        $this->invalid(fn () => $this->publish($product, $draft, 3, ['weight' => -1]), 'weight');
        $this->invalid(fn () => $this->publish($product, $draft, 3, ['draft_version' => 999]), 'inventory_quantity');
        config(['commerce.currency' => 'USD']);
        $this->invalid(fn () => app(CartService::class)->add($product->id, 1, $variant->id));
        $this->invalid(fn () => $this->publish($product, $draft), 'inventory_quantity');
    }

    public function test_admin_publication_action_and_storefront_show_options(): void
    {
        $product = $this->product();
        $draft = $this->draft($product);
        Livewire::withQueryParams(['product' => $product->id, 'draft' => $draft->id])->test(ProductVariantDrafts::class)
            ->callAction('publishVariant', data: ['draft_version' => 1, 'version' => 0, 'inventory_quantity' => 3, 'weight' => 1, 'active' => true])
            ->assertHasNoActionErrors()->assertSee('Published for sale')->assertSee('3 on hand');
        $this->get(route('products.show', $product))->assertOk()->assertSee('Choose your option')->assertSee('175.50')->assertSee('Size: Large');
        $this->get(route('products.index'))->assertOk()->assertSee('Choose options')->assertSee('From '.StoreMoney::format('175.50'));
    }

    public function test_checkout_controller_persists_two_options_and_reuses_the_gateway_link(): void
    {
        $product = $this->product();
        $large = $this->publish($product, $this->draft($product));
        $small = $this->publish($product, $this->draft($product, 'Small', '50.00'));
        $method = ShippingMethod::create(['name' => 'Local', 'base_rate' => 0, 'weight_rate' => 2, 'max_weight' => 10]);
        config(['services.ikhokha.app_id' => 'test-app', 'services.ikhokha.app_secret' => 'test-secret']);
        Http::fake(['https://api.ikhokha.com/*' => Http::response(['responseCode' => '00', 'paylinkID' => 'variant-link', 'paylinkUrl' => 'https://securepay.ikhokha.red/variant-test'])]);
        $address = ['email' => 'buyer@example.test', 'shipping_method_id' => $method->id, 'shipping_address' => '1 Rose Street',
            'shipping_city' => 'Johannesburg', 'shipping_country' => 'ZA', 'shipping_postal_code' => '2001',
            'delivery_contact_name' => 'Recipient', 'delivery_phone' => '+27821234567', 'payment_method' => 'ikhokha'];
        $this->withSession(['cart' => ["v:$product->id:$large->id" => ['quantity' => 1], "v:$product->id:$small->id" => ['quantity' => 2]]])
            ->post(route('checkout.process'), $address)->assertRedirect('https://securepay.ikhokha.red/variant-test');
        $this->post(route('checkout.process'), $address)->assertRedirect('https://securepay.ikhokha.red/variant-test');
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('stock_reservations', 2);
        $order = Order::firstOrFail();
        $this->assertEquals(7.5, $order->shipping_cost);
        $this->assertEquals(283.0, $order->total_amount);
        $this->assertSame([$large->id, $small->id], $order->items()->orderBy('id')->pluck('product_variant_id')->all());
        $this->assertSame(['Size' => 'Large'], $order->items()->first()->options_snapshot);
        Http::assertSentCount(1);
    }

    public function test_catalogue_sort_and_price_filters_use_live_option_prices(): void
    {
        $variable = $this->product();
        $this->publish($variable, $this->draft($variable));
        $simple = $this->product();
        $simple->update(['price' => 100]);
        $this->assertSame([$simple->id, $variable->id], Product::orderByStorePrice()->pluck('id')->all());
        $this->assertSame([$variable->id], Product::priceMin(150)->pluck('id')->all());
        $this->assertSame([$simple->id], Product::priceMax(150)->pluck('id')->all());
        $this->get(route('products.index', ['filter' => ['price_min' => 150], 'sort' => '-price']))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === [$variable->id]);
        $this->get(route('products.index', ['filter' => ['price' => 175.50]]))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === [$variable->id]);
        $this->get(route('products.search', ['min_price' => 150, 'max_price' => 200]))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === [$variable->id]);
    }

    public function test_price_race_is_rejected_at_reservation_and_unpublished_options_stay_hidden(): void
    {
        $product = $this->product();
        $draft = $this->draft($product);
        $variant = $this->publish($product, $draft, 3, ['active' => false]);
        $this->get(route('products.show', $product))->assertOk()->assertSee('No options are currently available')->assertDontSee($variant->sku);
        $variant = $this->publish($product, $draft);
        $this->invalid(fn () => DB::transaction(function () use ($product, $variant) {
            $order = Order::create(['customer_email' => 'buyer@example.test', 'order_date' => now(), 'currency' => 'ZAR', 'total_amount' => 10,
                'payment_status' => 'pending', 'status' => 'pending', 'shipping_status' => 'unfulfilled']);
            $order->items()->create(['product_id' => $product->id, 'product_variant_id' => $variant->id, 'price' => 10, 'quantity' => 1]);
            app(StockReservationService::class)->reserve($order);
        }));
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_live_sku_collision_and_archived_draft_cannot_be_published(): void
    {
        $product = $this->product();
        $draft = $this->draft($product);
        ProductVariant::create(['product_id' => $product->id, 'sku' => strtolower($draft->sku), 'price' => 10]);
        $this->invalid(fn () => $this->publish($product, $draft), 'inventory_quantity');
        $other = $this->draft($product, 'Small');
        $other->update(['archived' => true]);
        $this->invalid(fn () => $this->publish($product, $other), 'inventory_quantity');
    }
}
