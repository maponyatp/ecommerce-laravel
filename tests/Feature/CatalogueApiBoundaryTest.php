<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StoreApiStaff;
use Tests\TestCase;

class CatalogueApiBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use StoreApiStaff;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->grantStoreApiStaff($user);
        Sanctum::actingAs($user, ['catalog:read', 'catalog:write']);
        Http::preventStrayRequests();
    }

    public static function invalidQueries(): array
    {
        return [
            ['per_page=0'], ['per_page=101'], ['per_page=bad'], ['per_page[]=10'],
            ['search[]=flowers'], ['search='.str_repeat('a', 256)], ['category_id=bad'],
            ['category_id=999999'], ['price_min=-1'], ['price_max=100000000'],
            ['price_min=12.345'], ['price_min=20&price_max=10'],
            ['price_min[]=10&price_max[]=20'],
            ['sort_by=inventory_count'], ['sort_order=sideways'], ['sort_order[]=asc'],
        ];
    }

    #[DataProvider('invalidQueries')]
    public function test_malformed_catalogue_queries_return_validation_errors_without_side_effects(string $query): void
    {
        Product::factory()->create();
        $this->getJson('/api/products?'.$query)->assertUnprocessable()->assertJsonPath('success', false);
        $this->assertDatabaseCount('products', 1);
        Http::assertNothingSent();
    }

    public function test_price_filters_and_sorting_use_effective_free_price_and_exclude_unsupported_pricing(): void
    {
        $free = Product::factory()->create(['name' => 'Free', 'price' => 125]);
        $paid = Product::factory()->create(['name' => 'Paid', 'price' => 25]);
        $unsupported = Product::factory()->create(['name' => 'Unsupported', 'price' => 5]);
        DB::table('products')->where('id', $free->id)->update(['pricing_type' => 'free', 'price' => 125]);
        DB::table('products')->where('id', $unsupported->id)->update(['pricing_type' => 'donation']);

        $response = $this->getJson('/api/products?price_min=0&price_max=0&sort_by=price&sort_order=asc')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame([$free->id], collect($response->json('data'))->pluck('id')->all());
        $response = $this->getJson('/api/products?sort_by=price&sort_order=asc&per_page=100')->assertOk();
        $this->assertSame([$free->id, $paid->id, $unsupported->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertNull($response->json('data.2.effective_price'));
    }

    public function test_invalid_write_identifiers_return_not_found_instead_of_runtime_errors(): void
    {
        $product = Product::factory()->create(['name' => 'Unchanged']);
        foreach (['not-a-number', '1e2', str_repeat('9', 100)] as $identifier) {
            $this->patchJson('/api/products/'.$identifier, ['name' => 'Changed'])->assertNotFound();
            $this->deleteJson('/api/products/'.$identifier)->assertNotFound();
        }
        $this->assertSame('Unchanged', $product->fresh()->name);
    }

    public function test_catalogue_api_does_not_accept_or_return_private_download_paths(): void
    {
        $category = ProductCategory::factory()->create();
        $data = ['name' => 'Unsafe digital item', 'short_description' => 'Test', 'price' => 10,
            'category_id' => $category->id, 'is_downloadable' => true,
            'downloadable_file' => '../private/customer-file.pdf'];
        $this->postJson('/api/products', $data)->assertUnprocessable()
            ->assertJsonValidationErrors(['is_downloadable', 'downloadable_file']);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('downloadable_products', 0);

        $product = Product::factory()->create(['is_downloadable' => false]);
        $this->patchJson('/api/products/'.$product->id, ['is_downloadable' => true,
            'downloadable_file' => 'downloadable_products/forged.pdf'])->assertUnprocessable();
        $this->assertFalse($product->fresh()->is_downloadable);
        $this->assertDatabaseCount('downloadable_products', 0);

        DB::table('products')->where('id', $product->id)->update(['is_downloadable' => true,
            'downloadable_file' => 'downloadable_products/private.pdf']);
        $response = $this->getJson('/api/products/'.$product->id)->assertOk();
        $this->assertArrayNotHasKey('downloadable_file', $response->json('data'));
    }

    public function test_product_write_limits_prevent_database_exceptions_and_invalid_expiry_values(): void
    {
        $category = ProductCategory::factory()->create();
        $base = ['name' => 'Bounded product', 'short_description' => 'Test', 'price' => 10,
            'category_id' => $category->id];
        foreach ([
            ['name' => str_repeat('n', 256)], ['featured_image' => str_repeat('i', 256)],
            ['expiration_time' => 1], ['expiration_time' => 'not-a-date'],
            ['description' => str_repeat('d', 65536)], ['download_limit' => 10001],
        ] as $invalid) {
            $this->postJson('/api/products', array_replace($base, $invalid))->assertUnprocessable();
        }
        $this->assertDatabaseCount('products', 0);
    }

    public function test_product_api_accepts_a_bounded_future_expiry_value(): void
    {
        $category = ProductCategory::factory()->create();
        $expiry = now()->addDay()->setMicrosecond(0)->toIso8601String();

        $this->postJson('/api/products', [
            'name' => 'Expiring product',
            'short_description' => 'Test',
            'price' => 10,
            'category_id' => $category->id,
            'expiration_time' => $expiry,
        ])->assertCreated();

        $this->assertDatabaseHas('products', ['name' => 'Expiring product']);
    }

    public function test_private_api_responses_are_not_cacheable(): void
    {
        $product = Product::factory()->create();
        foreach (['/api/products', '/api/products/'.$product->id] as $path) {
            $this->getJson($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
                ->assertHeader('Pragma', 'no-cache');
        }
    }
}
