<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WishlistLifecycleRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_multi_product_wishlist_can_be_shared_and_reshared(): void
    {
        $user = User::factory()->create();
        $products = Product::factory()->count(2)->create();
        foreach ($products as $product) {
            Wishlist::create(['user_id' => $user->id, 'product_id' => $product->id]);
        }
        $this->actingAs($user)->post(route('wishlist.share'))->assertRedirect(route('wishlist.index'));
        $token = $user->wishlist()->first()->share_token;
        $this->assertNotEmpty($token);
        $this->assertSame(1, $user->wishlist()->distinct()->count('share_token'));
        $this->get(route('wishlist.shared', $token))->assertOk()
            ->assertSee($products[0]->name)->assertSee($products[1]->name);

        $this->post(route('wishlist.share'))->assertRedirect(route('wishlist.index'));
        $this->assertNotSame($token, $user->wishlist()->first()->share_token);
        $this->get(route('wishlist.shared', $token))->assertOk()->assertDontSee($products[0]->name);
    }

    public function test_deleted_products_do_not_break_private_or_shared_wishlists_and_can_be_removed(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['name' => 'Retired bouquet']);
        $entry = Wishlist::create(['user_id' => $user->id, 'product_id' => $product->id, 'share_token' => 'retired-link']);
        $product->delete();

        $this->actingAs($user)->get(route('wishlist.index'))->assertOk()->assertSee('Product no longer available');
        $this->get(route('wishlist.shared', 'retired-link'))->assertOk()->assertDontSee('Retired bouquet');
        $this->delete(route('wishlist.remove', $product))->assertRedirect();
        $this->assertDatabaseMissing('wishlists', ['id' => $entry->id]);
    }

    public function test_removing_a_deleted_product_cannot_remove_someone_elses_entry(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $product = Product::factory()->create();
        $entry = Wishlist::create(['user_id' => $owner->id, 'product_id' => $product->id]);
        $product->delete();
        $this->actingAs($other)->delete(route('wishlist.remove', $product))->assertNotFound();
        $this->assertDatabaseHas('wishlists', ['id' => $entry->id]);
    }

    public function test_wishlist_pages_are_private_and_do_not_leak_share_tokens_to_referrers(): void
    {
        $user = User::factory()->create();
        foreach ([route('wishlist.index'), route('wishlist.shared', 'no-such-list')] as $url) {
            $response = $this->actingAs($user)->get($url)->assertOk()
                ->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
    }

    public function test_migration_preserves_existing_shares_and_user_product_uniqueness(): void
    {
        $migration = require database_path('migrations/2026_08_28_180000_fix_wishlist_share_token_index.php');
        $migration->down();
        $user = User::factory()->create();
        $products = Product::factory()->count(2)->create();
        $entry = Wishlist::create(['user_id' => $user->id, 'product_id' => $products[0]->id, 'share_token' => 'existing-share']);
        $migration->up();
        $this->assertSame('existing-share', $entry->fresh()->share_token);
        Wishlist::create(['user_id' => $user->id, 'product_id' => $products[1]->id, 'share_token' => 'existing-share']);
        $indexes = collect(Schema::getIndexes('wishlists'))->keyBy('name');
        $this->assertFalse($indexes['wishlists_share_token_index']['unique']);
        $this->assertTrue($indexes['wishlists_user_id_product_id_unique']['unique']);
        try {
            $migration->down();
            $this->fail('Rollback must not discard active shared lists.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('multi-product shared lists exist', $exception->getMessage());
        }
        $this->assertSame(2, DB::table('wishlists')->where('share_token', 'existing-share')->count());
    }
}
