<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Products\Pages\CreateProduct;
use App\Filament\Admin\Resources\Products\Pages\EditProduct;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\CartService;
use App\Services\OrderPaymentService;
use App\Services\StockReservationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductPricingRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        config(['services.ikhokha.app_id' => null, 'services.ikhokha.app_secret' => null,
            'services.stripe.key' => null, 'services.stripe.secret' => null]);
    }

    private function product(array $extra = [], ?string $legacyType = null): Product
    {
        $category = ProductCategory::create(['name' => 'Flowers', 'slug' => 'flowers-'.uniqid()]);
        $product = Product::create($extra + ['name' => 'Pricing test bouquet', 'slug' => 'bouquet-'.uniqid(),
            'category_id' => $category->id, 'pricing_type' => 'fixed', 'price' => 125,
            'inventory_count' => 10, 'low_stock_threshold' => 1, 'is_downloadable' => false]);
        if ($legacyType !== null) {
            DB::table('products')->where('id', $product->id)->update(['pricing_type' => $legacyType]);
        }

        return $product->fresh();
    }

    private function admin(): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $actor = User::factory()->create();
        $actor->assignRole(Role::findOrCreate('admin', 'web'));
        foreach (['view_any_product', 'view_product', 'create_product', 'update_product'] as $permission) {
            $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $actor;
    }

    public function test_legacy_free_price_is_zero_in_cart_without_rewriting_product_or_session(): void
    {
        $product = $this->product(legacyType: 'free');
        $cart = [$product->id => ['quantity' => 2, 'price' => 999]];
        session(['cart' => $cart]);
        $line = app(CartService::class)->hydrate($cart)[$product->id];
        $this->assertSame('0.00', $line['price']);
        $this->assertSame('125.00', $product->fresh()->price);
        $this->assertSame($cart, session('cart'));
        $this->assertSame('ZAR 0.00', $product->store_price_label);
        $this->get(route('products.show', $product))->assertOk()->assertSee('delivery charges may still apply')
            ->assertDontSee('free checkout')->assertSee('"price": "0.00"', false);
        Http::assertNothingSent();
    }

    public function test_legacy_free_digital_checkout_issues_zero_invoice_and_download_without_gateway(): void
    {
        Storage::disk('local')->put('downloadable_products/free-guide.pdf', 'synthetic');
        $product = $this->product(['is_downloadable' => true, 'downloadable_file' => 'downloadable_products/free-guide.pdf'], 'free');
        $this->withSession(['cart' => [$product->id => ['quantity' => 1, 'price' => 125]]]);
        $this->post(route('checkout.process'), ['email' => 'buyer@example.test'])->assertRedirect()->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $this->assertSame('0.00', $order->total_amount);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(0.0, (float) $order->items->first()->price);
        $this->assertNotNull($order->items->first()->download_path);
        $this->assertEquals(0, $order->invoice->total_amount);
        $this->assertSame(9, $product->fresh()->inventory_count);
        $this->assertDatabaseCount('payment_transactions', 0);
        Http::assertNothingSent();
    }

    public function test_free_physical_item_does_not_waive_delivery_charges(): void
    {
        $product = $this->product(legacyType: 'free');
        $method = ShippingMethod::create(['name' => 'Local delivery', 'base_rate' => 25,
            'weight_rate' => 0, 'max_weight' => 10, 'is_active' => true]);
        $this->withSession(['cart' => [$product->id => ['quantity' => 1]]]);
        $address = ['email' => 'buyer@example.test', 'shipping_method_id' => $method->id,
            'delivery_contact_name' => 'Recipient', 'delivery_phone' => '+27821234567',
            'shipping_address' => 'Synthetic address', 'shipping_country' => 'ZA',
            'shipping_city' => 'Pretoria', 'shipping_postal_code' => '0001'];
        $this->postJson(route('checkout.quote'), $address)->assertOk()->assertJsonPath('subtotal', 0)
            ->assertJsonPath('shipping', 25)->assertJsonPath('total', 25);
        $this->post(route('checkout.process'), $address)->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_admin_can_create_a_free_product_without_a_hidden_price_field(): void
    {
        $this->admin();
        Livewire::test(CreateProduct::class)->fillForm(['name' => 'Free gift', 'slug' => 'free-gift',
            'pricing_type' => 'free', 'inventory_count' => 3, 'low_stock_threshold' => 1,
            'is_downloadable' => false])->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseHas('products', ['slug' => 'free-gift', 'pricing_type' => 'free', 'price' => 0]);
    }

    public function test_admin_switch_to_free_clears_previous_price_without_overwriting_stock(): void
    {
        $this->admin();
        $product = $this->product();
        $page = Livewire::test(EditProduct::class, ['record' => $product->slug]);
        DB::table('products')->where('id', $product->id)->update(['inventory_count' => 7]);
        $page->fillForm(['pricing_type' => 'free', 'inventory_count' => 100])->call('save')->assertHasNoFormErrors();
        $this->assertSame('0.00', $product->fresh()->price);
        $this->assertSame(7, $product->fresh()->inventory_count);
    }

    public function test_nullable_pricing_defaults_to_fixed_without_a_database_error(): void
    {
        $this->admin();
        $product = $this->product(['pricing_type' => null]);
        Livewire::test(EditProduct::class, ['record' => $product->slug])
            ->fillForm(['description' => 'Updated legacy product'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('125.00', $product->fresh()->price);
        $this->assertSame('fixed', $product->fresh()->pricing_type);
    }

    public static function invalidPrices(): array
    {
        return [[-1], ['100000000.00'], ['12.345']];
    }

    #[DataProvider('invalidPrices')]
    public function test_admin_rejects_invalid_fixed_prices_before_database_write(mixed $price): void
    {
        $this->admin();
        $product = $this->product();
        Livewire::test(EditProduct::class, ['record' => $product->slug])->fillForm(['price' => $price])
            ->call('save')->assertHasFormErrors(['price']);
        $this->assertSame('125.00', $product->fresh()->price);
    }

    public function test_admin_cannot_enable_unimplemented_donation_pricing(): void
    {
        $this->admin();
        $product = $this->product();
        Livewire::test(EditProduct::class, ['record' => $product->slug])->fillForm(['pricing_type' => 'donation'])
            ->call('save')->assertHasFormErrors(['pricing_type']);
        $this->assertSame('fixed', $product->fresh()->pricing_type);
    }

    public function test_catalogue_api_free_creation_and_unsupported_pricing_validation(): void
    {
        Sanctum::actingAs($this->admin(), ['catalog:write']);
        $category = ProductCategory::create(['name' => 'API', 'slug' => 'api']);
        $data = ['name' => 'Free API gift', 'short_description' => 'Gift', 'category_id' => $category->id,
            'pricing_type' => 'free', 'inventory_count' => 2];
        $response = $this->postJson('/api/products', $data)->assertCreated();
        $id = $response->json('data.id');
        $this->assertDatabaseHas('products', ['id' => $id, 'price' => 0]);
        $this->patchJson('/api/products/'.$id, ['pricing_type' => 'donation'])->assertUnprocessable();
        $this->postJson('/api/products', array_replace($data, ['pricing_type' => 'donation', 'price' => 25]))->assertUnprocessable();
        foreach (self::invalidPrices() as [$price]) {
            $this->patchJson('/api/products/'.$id, ['pricing_type' => 'fixed', 'price' => $price])->assertUnprocessable();
        }
        $this->assertDatabaseCount('products', 1);
        $this->patchJson('/api/products/'.$id, ['pricing_type' => null, 'price' => 25])->assertOk();
        $this->assertDatabaseHas('products', ['id' => $id, 'pricing_type' => 'fixed', 'price' => 25]);
    }

    public static function unsupportedTypes(): array
    {
        return [['donation'], ['subscription']];
    }

    #[DataProvider('unsupportedTypes')]
    public function test_legacy_unsupported_pricing_cannot_silently_charge_a_fixed_price(string $type): void
    {
        $product = $this->product(legacyType: $type);
        $this->post(route('cart.add', $product), ['price' => 100, 'quantity' => 1])->assertSessionHas('error');
        $this->assertEmpty(session('cart', []));
        session(['cart' => [$product->id => ['quantity' => 1, 'price' => 0]]]);
        $this->postJson(route('checkout.quote'), [])->assertUnprocessable();
        $this->get(route('products.show', $product))->assertOk()->assertSee('Pricing option unavailable')
            ->assertDontSee('id="donation_amount"', false)->assertDontSee('Support &amp; Download', false)
            ->assertDontSee('"@type": "Offer"', false);
        $this->blade('<x-catalogue-buy-button :product="$product" />', compact('product'))
            ->assertSee('Unavailable')->assertDontSee('method="POST"', false);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payment_transactions', 0);
        Http::assertNothingSent();
    }

    public function test_price_filters_and_sorting_use_zero_for_legacy_free_products(): void
    {
        $free = $this->product(legacyType: 'free');
        $paid = $this->product(['price' => 10]);
        $donation = $this->product(legacyType: 'donation');
        $this->assertSame([$free->id], Product::query()->priceMin(0)->priceMax(0)->pluck('id')->all());
        $this->assertSame([$free->id, $paid->id], Product::query()->whereKey([$free->id, $paid->id])->orderByStorePrice()->pluck('id')->all());
        $this->assertFalse(Product::query()->whereKey($donation->id)->priceMin(0)->exists());
    }

    public function test_reservation_rechecks_free_pricing_under_the_product_lock(): void
    {
        $product = $this->product(legacyType: 'free');
        $order = Order::create(['customer_email' => 'buyer@example.test', 'order_date' => now(),
            'currency' => 'ZAR', 'total_amount' => 0, 'payment_status' => 'pending', 'status' => 'awaiting_payment', 'shipping_status' => 'unfulfilled']);
        $order->items()->create(['product_id' => $product->id, 'price' => 0, 'quantity' => 1]);
        DB::transaction(fn () => app(StockReservationService::class)->reserve($order));
        $this->assertDatabaseCount('stock_reservations', 1);
        $this->assertSame('held', $order->fresh()->stock_reservation_status);
    }

    public function test_reservation_rejects_a_stale_paid_price_after_product_becomes_free(): void
    {
        $product = $this->product(legacyType: 'free');
        $order = Order::create(['customer_email' => 'buyer@example.test', 'order_date' => now(),
            'currency' => 'ZAR', 'total_amount' => 125, 'payment_status' => 'pending', 'status' => 'awaiting_payment', 'shipping_status' => 'unfulfilled']);
        $order->items()->create(['product_id' => $product->id, 'price' => 125, 'quantity' => 1]);
        try {
            DB::transaction(fn () => app(StockReservationService::class)->reserve($order));
            $this->fail('Stale paid price was reserved for a free product.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cart', $exception->errors());
        }
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_existing_payment_keeps_its_agreed_amount_after_catalogue_switches_to_free(): void
    {
        $product = $this->product();
        $order = Order::create(['customer_email' => 'buyer@example.test', 'order_date' => now(),
            'currency' => 'ZAR', 'total_amount' => 125, 'payment_status' => 'pending',
            'status' => 'awaiting_payment', 'shipping_status' => 'unfulfilled']);
        $order->items()->create(['product_id' => $product->id, 'price' => 125, 'quantity' => 1]);
        DB::transaction(fn () => app(StockReservationService::class)->reserve($order));
        $transaction = PaymentTransaction::create(['order_id' => $order->id, 'gateway' => 'ikhokha',
            'external_transaction_id' => 'synthetic-old-price', 'amount' => 125, 'currency' => 'ZAR', 'status' => 'pending']);
        $product->update(['pricing_type' => 'free']);
        app(OrderPaymentService::class)->markPaid($transaction);
        $order->refresh();
        $this->assertSame('125.00', $order->total_amount);
        $this->assertSame('paid', $order->payment_status);
        $this->assertEquals(125, $order->items->first()->price);
        $this->assertEquals(125, $order->invoice->total_amount);
        $this->assertSame('0.00', $product->fresh()->price);
        Http::assertNothingSent();
    }

    public function test_products_without_a_category_can_render_and_be_added_to_cart(): void
    {
        $product = $this->product(['category_id' => null]);
        $this->get(route('products.show', $product))->assertOk()->assertDontSee('Add to Compare');
        $this->post(route('cart.add', $product))->assertRedirect()->assertSessionHas('success');
        $this->assertArrayHasKey($product->id, session('cart'));
    }

    public function test_comparison_stays_usable_after_a_product_loses_its_category(): void
    {
        $product = $this->product();
        session(['compare_list' => [$product->id]]);
        $product->update(['category_id' => null]);
        $this->get(route('products.compare'))->assertOk()->assertSee($product->name)->assertSee('Clear comparison');
        $this->delete(route('products.clearCompare'))->assertRedirect();
        $this->assertNull(session('compare_list'));
    }
}
