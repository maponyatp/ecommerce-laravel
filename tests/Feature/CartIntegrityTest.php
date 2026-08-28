<?php

namespace Tests\Feature;

use App\Livewire\ShoppingCart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\CartService;
use App\Services\StockReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class CartIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    private function product(array $extra = []): Product
    {
        $category = ProductCategory::create(['name' => 'Flowers', 'slug' => 'flowers-'.uniqid()]);

        return Product::create(array_merge(['name' => "Florist's bouquet", 'slug' => 'bouquet-'.uniqid(),
            'category_id' => $category->id, 'price' => 125, 'inventory_count' => 10, 'is_downloadable' => false], $extra));
    }

    private function cart(Product $product, mixed $quantity = 1): array
    {
        return [$product->id => ['name' => 'CLIENT_NAME', 'price' => 0.01, 'quantity' => $quantity,
            'is_downloadable' => true, 'weight' => -100, 'image' => 'https://invalid.example/tracker', 'extra' => 'CLIENT_EXTRA']];
    }

    private function hold(Product $product, int $quantity): Order
    {
        return DB::transaction(function () use ($product, $quantity) {
            $order = Order::create(['customer_email' => 'hold@example.test', 'order_date' => now(), 'currency' => 'ZAR',
                'total_amount' => $product->price * $quantity, 'payment_status' => 'pending', 'status' => 'pending', 'shipping_status' => 'unfulfilled']);
            $order->items()->create(['product_id' => $product->id, 'quantity' => $quantity, 'price' => $product->price]);
            app(StockReservationService::class)->reserve($order);

            return $order;
        });
    }

    public function test_livewire_ignores_browser_product_metadata(): void
    {
        $product = $this->product();
        Livewire::test(ShoppingCart::class)->call('addToCart', $product->id, 'FORGED', -999, 2, true, -900)
            ->assertHasNoErrors()->assertDispatched('cartUpdated')->assertSee("Florist's bouquet");
        $line = session('cart')[$product->id];
        $this->assertSame($product->name, $line['name']);
        $this->assertEquals(125, $line['price']);
        $this->assertSame(2, $line['quantity']);
        $this->assertFalse($line['is_downloadable']);
        $this->assertEquals(0, $line['weight']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_both_component_names_use_the_same_safe_service(): void
    {
        $product = $this->product();
        Livewire::test(\App\Http\Livewire\ShoppingCart::class)->call('addToCart', $product->id, 'FORGED', -5)
            ->assertHasNoErrors();
        $this->assertEquals(125, session('cart')[$product->id]['price']);
    }

    public function test_client_cannot_replace_cart_state(): void
    {
        $product = $this->product();
        app(CartService::class)->add($product->id);
        $before = session('cart');
        try {
            Livewire::test(ShoppingCart::class)->set('items.'.$product->id.'.price', 0);
            $this->fail('Cart state must be locked.');
        } catch (CannotUpdateLockedPropertyException) {
            $this->assertSame($before, session('cart'));
        }
    }

    public function test_invalid_http_quantities_are_rejected_without_mutating_cart(): void
    {
        $product = $this->product(['inventory_count' => 20000]);
        $this->post(route('cart.add', $product));
        $before = session('cart');
        foreach ([0, -1, '1.5', '1e2', 'bad', [], true, null, 10000, '999999999999999999999'] as $quantity) {
            $this->post(route('cart.add', $product), ['quantity' => $quantity])->assertRedirect()->assertSessionHas('error');
            $this->assertSame($before, session('cart'));
            $this->put(route('cart.update', $product->id), ['quantity' => $quantity])->assertRedirect()->assertSessionHas('error');
            $this->assertSame($before, session('cart'));
        }
    }

    public function test_livewire_rejects_invalid_quantities_and_missing_products(): void
    {
        $product = $this->product();
        $component = Livewire::test(ShoppingCart::class);
        foreach ([0, -2, '2.5', [], true, 10000] as $quantity) {
            $component->call('addToCart', $product->id, null, null, $quantity)->assertHasErrors('cart');
            $this->assertEmpty(session('cart', []));
        }
        $component->call('addToCart', 999999)->assertHasErrors('cart')
            ->call('addToCart', $product->id)->assertHasNoErrors();
        $before = session('cart');
        $component->call('updateQuantity', $product->id, '1.8')->assertHasErrors('cart');
        $this->assertSame($before, session('cart'));
    }

    public function test_add_and_update_respect_other_checkout_holds(): void
    {
        $product = $this->product(['inventory_count' => 3]);
        $hold = $this->hold($product, 2);
        $this->post(route('cart.add', $product))->assertSessionHas('success');
        $before = session('cart');
        $this->post(route('cart.add', $product))->assertSessionHas('error');
        $this->put(route('cart.update', $product->id), ['quantity' => 2])->assertSessionHas('error');
        Livewire::test(ShoppingCart::class)->call('updateQuantity', $product->id, 2)->assertHasErrors('cart');
        $this->assertSame($before, session('cart'));
        $this->assertSame(3, $product->fresh()->inventory_count);
        app(StockReservationService::class)->release($hold);
        $this->put(route('cart.update', $product->id), ['quantity' => 2])->assertSessionHas('success');
        $this->assertSame(2, session('cart')[$product->id]['quantity']);
    }

    public function test_expired_holds_do_not_block_cart(): void
    {
        $product = $this->product(['inventory_count' => 1]);
        $this->hold($product, 1);
        $this->travel(31)->minutes();
        $this->post(route('cart.add', $product))->assertSessionHas('success');
        $this->assertSame(1, session('cart')[$product->id]['quantity']);
    }

    public function test_digital_flag_cannot_bypass_stock_and_missing_file_is_rejected(): void
    {
        $physical = $this->product(['inventory_count' => 0]);
        Livewire::test(ShoppingCart::class)->call('addToCart', $physical->id, 'fake', 0, 1, true)->assertHasErrors('cart');
        $digital = $this->product(['is_downloadable' => true, 'downloadable_file' => 'downloadable_products/cart.pdf']);
        $this->post(route('cart.add', $digital))->assertSessionHas('error');
        Storage::disk('local')->put('downloadable_products/cart.pdf', 'fixture');
        $this->post(route('cart.add', $digital))->assertSessionHas('success');
        $this->assertTrue(session('cart')[$digital->id]['is_downloadable']);
        Storage::disk('local')->delete('downloadable_products/cart.pdf');
        $before = session('cart');
        Livewire::test(ShoppingCart::class)->assertSee('unavailable for delivery')
            ->call('updateQuantity', $digital->id, 2)->assertHasErrors('cart');
        $this->assertSame($before, session('cart'));
    }

    public function test_display_reprices_without_rewriting_pending_checkout_cart_hash(): void
    {
        $product = $this->product();
        $cart = $this->cart($product, 2);
        session(['cart' => $cart, 'checkout_quote' => 'old-quote', 'pending_checkout' => ['cart_hash' => hash('sha256', serialize($cart))]]);
        Livewire::test(ShoppingCart::class)->assertSee("Florist's bouquet")->assertDontSee('CLIENT_NAME')->assertDontSee('invalid.example')
            ->assertSet('items.'.$product->id.'.price', '125.00');
        $this->assertSame($cart, session('cart'));
        $this->assertSame('old-quote', session('checkout_quote'));
        $product->update(['price' => 150]);
        $this->get(route('cart.index'))->assertOk()->assertSee('150')->assertDontSee('CLIENT_NAME');
        $this->get(route('checkout.initiate'))->assertOk()->assertViewHas('cart', fn ($items) => $items[$product->id]['price'] === '150.00' && ! isset($items[$product->id]['extra']));
        $this->assertSame($cart, session('cart'));
        $this->assertSame(hash('sha256', serialize($cart)), session('pending_checkout.cart_hash'));
    }

    public function test_stale_component_actions_use_latest_session_not_old_rows(): void
    {
        $first = $this->product();
        $second = $this->product(['name' => 'Second bouquet']);
        app(CartService::class)->add($first->id);
        $component = Livewire::test(ShoppingCart::class);
        app(CartService::class)->add($second->id);
        $component->call('updateQuantity', $first->id, 2)->assertHasNoErrors();
        $this->assertArrayHasKey($second->id, session('cart'));
        app(CartService::class)->remove($first->id);
        $component->call('updateQuantity', $first->id, 3)->assertHasErrors('cart');
        $this->assertArrayNotHasKey($first->id, session('cart'));
        $this->assertArrayHasKey($second->id, session('cart'));
    }

    public function test_deleted_product_remains_removable_and_blocks_checkout_link(): void
    {
        $product = $this->product();
        app(CartService::class)->add($product->id);
        $product->delete();
        Livewire::test(ShoppingCart::class)->assertSee('Unavailable product')->assertDontSee('Proceed to Checkout')
            ->call('removeItem', $product->id)->assertHasNoErrors()->assertSee('Your cart is empty');
        $this->assertEmpty(session('cart'));
    }

    public function test_unavailable_quantities_remain_editable(): void
    {
        $product = $this->product(['inventory_count' => 2]);
        session(['cart' => $this->cart($product, 5)]);
        Livewire::test(ShoppingCart::class)->assertSee('Requested quantity exceeds')->assertDontSee('Proceed to Checkout')
            ->call('updateQuantity', $product->id, '2')->assertHasNoErrors()->assertSee('Proceed to Checkout');
        $this->assertSame(2, session('cart')[$product->id]['quantity']);
    }

    public function test_malformed_saved_cart_is_visible_and_clearable(): void
    {
        foreach (['invalid', ['bad-reference' => ['quantity' => 1]]] as $cart) {
            session(['cart' => $cart]);
            $this->get(route('cart.index'))->assertOk()->assertSee('Clear saved cart')->assertDontSee('Proceed to Checkout');
            Livewire::test(ShoppingCart::class)->call('clearCart')->assertHasNoErrors();
            $this->assertNull(session('cart'));
        }
    }

    public function test_coupon_uses_current_database_prices_and_rejects_unavailable_items(): void
    {
        $product = $this->product(['price' => 100]);
        Coupon::create(['code' => 'CART10', 'type' => 'percentage', 'value' => 10, 'valid_from' => now()->subDay(), 'valid_until' => now()->addDay()]);
        session(['cart' => $this->cart($product, 2), 'checkout_quote' => 'old']);
        $this->post(route('cart.apply-coupon'), ['coupon_code' => 'CART10'])->assertSessionHas('success');
        $this->assertEquals(20, session('coupon.discount'));
        $this->assertNull(session('checkout_quote'));
        $product->update(['inventory_count' => 0]);
        $this->post(route('cart.apply-coupon'), ['coupon_code' => 'CART10'])->assertSessionHas('error');
    }

    public function test_clear_and_remove_preserve_payment_recovery_but_clear_coupon_and_quote(): void
    {
        $product = $this->product();
        app(CartService::class)->add($product->id);
        $pending = ['order_id' => 123, 'cart_hash' => 'old'];
        session(['pending_checkout' => $pending, 'checkout_last_key' => 'idempotent', 'coupon' => ['code' => 'CART10'], 'checkout_quote' => 'old']);
        Livewire::test(ShoppingCart::class)->call('removeItem', $product->id)->assertHasNoErrors();
        $this->assertNull(session('coupon'));
        $this->assertNull(session('checkout_quote'));
        $this->assertSame($pending, session('pending_checkout'));
        session(['coupon' => ['code' => 'CART10'], 'checkout_quote' => 'old']);
        Livewire::test(ShoppingCart::class)->call('clearCart')->assertHasNoErrors();
        $this->assertNull(session('coupon'));
        $this->assertNull(session('checkout_quote'));
        $this->assertSame('idempotent', session('checkout_last_key'));
        $this->assertSame($pending, session('pending_checkout'));
    }

    public function test_product_card_has_working_csrf_post_without_legacy_emit(): void
    {
        $product = $this->product();
        $view = $this->blade('<x-products_section :products="$products" />', ['products' => collect([$product])]);
        $view->assertSee('method="POST"', false)->assertSee(route('cart.add', $product), false)
            ->assertSee('name="_token"', false)->assertDontSee('$emitTo');
    }

    public function test_own_active_hold_offers_existing_checkout_without_new_order(): void
    {
        $product = $this->product(['inventory_count' => 1]);
        app(CartService::class)->add($product->id);
        $order = $this->hold($product, 1);
        $order->update(['checkout_key' => str_repeat('a', 64)]);
        session(['checkout_last_key' => $order->checkout_key, 'checkout_last_cart' => hash('sha256', serialize(session('cart')))]);
        Livewire::test(ShoppingCart::class)->assertSee('Review existing checkout')->assertDontSee('Proceed to Checkout');
        $this->get(route('checkout.initiate'))->assertRedirect();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('stock_reservations', 1);
        $this->assertSame('pending', $order->fresh()->payment_status);
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_changed_or_expired_cart_cannot_offer_the_old_checkout(): void
    {
        $product = $this->product(['inventory_count' => 1]);
        app(CartService::class)->add($product->id);
        $order = $this->hold($product, 1);
        $order->update(['checkout_key' => str_repeat('b', 64)]);
        $hash = hash('sha256', serialize(session('cart')));
        session(['checkout_last_key' => $order->checkout_key, 'checkout_last_cart' => 'different-cart']);
        Livewire::test(ShoppingCart::class)->assertDontSee('Review existing checkout');
        session(['checkout_last_cart' => $hash]);
        $this->travel(31)->minutes();
        Livewire::test(ShoppingCart::class)->assertDontSee('Review existing checkout')->assertSee('Proceed to Checkout');
        $order->update(['status' => 'payment_review']);
        Livewire::test(ShoppingCart::class)->assertSee('Review existing checkout')->assertDontSee('Proceed to Checkout');
    }
}
