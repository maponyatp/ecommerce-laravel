<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductComparisonTest extends TestCase
{
    use RefreshDatabase;

    private function url(string $name, Product $product): string
    {
        return route($name, ['category' => $product->category, 'product' => $product]);
    }

    public function test_empty_comparison_route_is_not_captured_by_product_route(): void
    {
        $this->get('/products/compare')->assertOk()->assertSee('No products selected');
    }

    public function test_add_compare_remove_and_clear_work_with_server_prices(): void
    {
        $product = Product::factory()->create(['name' => 'Pink bouquet', 'price' => 150]);
        $this->get(route('products.show', $product))->assertOk()->assertSee('Add to Compare');
        $this->post($this->url('products.addToCompare', $product), ['price' => 1])->assertRedirect(route('products.compare'));
        $this->get('/products/compare')->assertOk()->assertSee('Pink bouquet')->assertSee('ZAR 150.00')->assertSee($product->category->name);
        $this->post($this->url('products.addToCompare', $product))->assertRedirect();
        $this->assertSame([$product->id], session('compare_list'));
        $this->delete($this->url('products.removeFromCompare', $product))->assertRedirect(route('products.compare'));
        $this->get('/products/compare')->assertOk()->assertSee('No products selected');
        $this->post($this->url('products.addToCompare', $product))->assertRedirect();
        $this->delete(route('products.clearCompare'))->assertRedirect();
        $this->assertNull(session('compare_list'));
    }

    public function test_maximum_four_products_and_wrong_category_rejection(): void
    {
        $products = Product::factory()->count(5)->create();
        foreach ($products as $product) {
            $this->post($this->url('products.addToCompare', $product))->assertRedirect();
        }
        $this->assertCount(4, session('compare_list'));
        $this->get('/products/compare')->assertOk()->assertSee('Compare up to four products');
        $other = ProductCategory::factory()->create();
        $this->post(route('products.addToCompare', ['category' => $other, 'product' => $products->first()]))->assertNotFound();
    }

    public function test_deleted_products_and_malformed_session_entries_do_not_break_comparison(): void
    {
        $product = Product::factory()->create(['name' => '<script>Flower</script>']);
        $deleted = Product::factory()->create();
        $deleted->delete();
        $this->withSession(['compare_list' => [$product->id, $deleted->id, ['bad'], 'bad', null]])
            ->get('/products/compare')->assertOk()->assertSee('&lt;script&gt;Flower&lt;/script&gt;', false);
        $this->withSession(['compare_list' => 'bad'])->get('/products/compare')->assertOk()->assertSee('No products selected');
    }
}
