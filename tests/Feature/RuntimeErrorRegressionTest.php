<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductCollection;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RuntimeErrorRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_search_header_ignores_non_text_keywords_on_other_pages(): void
    {
        foreach (['/', '/cart', '/login', '/contact'] as $path) {
            $this->get($path.'?keyword%5B%5D=roses')->assertOk();
        }
    }

    public function test_array_keyword_is_rejected_instead_of_crashing_search(): void
    {
        $this->getJson('/products/search?keyword[]=roses')->assertUnprocessable()->assertJsonValidationErrors('keyword');
    }

    public function test_array_keyword_is_rejected_instead_of_crashing_catalogue(): void
    {
        $this->getJson('/products?keyword[]=roses')->assertUnprocessable()->assertJsonValidationErrors('keyword');
    }

    public function test_malformed_catalogue_parameters_are_rejected_on_all_listing_routes(): void
    {
        $category = ProductCategory::factory()->create();
        $collection = ProductCollection::factory()->create();
        $tag = Tag::factory()->create();
        foreach (['/products', '/categories', '/collections', '/tags', '/categories/'.$category->getRouteKey().'/products',
            '/collections/'.$collection->getRouteKey().'/products', '/tags/'.$tag->getRouteKey()] as $path) {
            foreach (['filter' => 'not-an-array', 'sort' => ['price'], 'page' => ['1'], 'filter.price_min' => ['20']] as $field => $value) {
                $query = [];
                data_set($query, $field, $value);
                $this->getJson($path.'?'.http_build_query($query))->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
    }

    public function test_search_rejects_invalid_prices_and_category_shapes(): void
    {
        foreach (['min_price' => '-1', 'max_price' => 'not-a-price', 'category' => ['1']] as $key => $value) {
            $this->getJson('/products/search?'.http_build_query([$key => $value]))->assertUnprocessable()->assertJsonValidationErrors($key);
        }
    }

    public function test_valid_search_and_filters_keep_working(): void
    {
        $product = Product::factory()->create(['name' => 'Runtime Roses', 'price' => 125]);
        $this->get('/products/search?keyword=Runtime&min_price=100&max_price=150')->assertOk()->assertSee($product->name);
        $this->get('/products?filter[price_min]=100&filter[price_max]=150&sort=price')->assertOk()->assertSee($product->name);
    }

    public function test_unconfigured_oauth_provider_has_controlled_response_without_network_calls(): void
    {
        config(['services.google' => null]);
        $this->getJson('/oauth/google')->assertStatus(503);
        $this->getJson('/oauth/google/callback')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_unknown_oauth_provider_is_not_resolved(): void
    {
        $this->getJson('/oauth/not-a-real-provider')->assertNotFound();
        $this->getJson('/oauth/not-a-real-provider/callback')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_unconfigured_oauth_links_are_not_advertised(): void
    {
        config(['services.google' => null]);
        $this->get('/login')->assertOk()->assertDontSee('href="'.route('oauth.redirect', 'google').'"', false)
            ->assertDontSee("href='".route('oauth.redirect', 'google')."'", false);
    }

    public function test_configured_oauth_link_renders_and_demo_credentials_are_not_advertised(): void
    {
        config(['services.google' => ['client_id' => 'test-client', 'client_secret' => 'test-secret', 'redirect' => 'http://localhost/oauth/google/callback']]);
        foreach (['/login', '/register'] as $path) {
            $response = $this->get($path)->assertOk();
            $this->assertStringContainsString(route('oauth.redirect', 'google'), $response->getContent());
            $this->assertStringNotContainsString('Demo Credentials', $response->getContent());
            $this->assertStringNotContainsString('admin@example.com', $response->getContent());
        }
    }

    public function test_oauth_account_link_confirmation_requires_authentication(): void
    {
        config(['services.google' => ['client_id' => 'test-client', 'client_secret' => 'test-secret', 'redirect' => 'http://localhost/oauth/google/callback']]);
        $this->postJson('/oauth/google/callback/confirm', ['result' => 'confirm'])->assertUnauthorized();
    }

    public function test_expired_oauth_account_link_is_a_validation_error(): void
    {
        config(['services.google' => ['client_id' => 'test-client', 'client_secret' => 'test-secret', 'redirect' => 'http://localhost/oauth/google/callback']]);
        $this->actingAs(User::factory()->create())->postJson('/oauth/google/callback/confirm', ['result' => 'confirm'])
            ->assertUnprocessable()->assertJsonValidationErrors('socialstream');
        Http::assertNothingSent();
    }
}
