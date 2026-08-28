<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ReviewControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $staff = User::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $staff->assignRole(Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']));
        $this->actingAs($staff);
    }

    public function test_store()
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('reviews.store'), [
                'product_id' => $product->id,
                'rating' => 5,
                'review' => 'Great product!',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('reviews', [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'rating' => 5,
            'review' => 'Great product!',
            'approved' => false,
        ]);
    }

    public function test_approve()
    {

        $review = Review::factory()->create([
            'rating' => 5,
            'review' => 'Great product!',
            'approved' => false,
        ]);

        $this->post(route('reviews.approve', ['id' => $review->id]));

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'approved' => true,
        ]);
    }

    public function test_approve_returns_404_for_missing_review(): void
    {
        $response = $this->postJson('/reviews/approve/9999');

        $response->assertStatus(404);
    }

    public function test_show_returns_only_approved_reviews(): void
    {
        $product = Product::factory()->create();
        $approved = Review::factory()->create([
            'product_id' => $product->id,
            'approved' => true,
        ]);
        $pending = Review::factory()->create([
            'product_id' => $product->id,
            'approved' => false,
        ]);

        $response = $this->getJson("/product/{$product->id}/reviews");

        $response->assertStatus(200);
        $ids = array_column($response->json(), 'id');
        $this->assertContains($approved->id, $ids);
        $this->assertNotContains($pending->id, $ids);
    }

    public function test_vote_helpful_increments_helpful_votes(): void
    {
        $review = Review::factory()->create(['helpful_votes' => 0, 'approved' => true]);

        $response = $this->postJson("/reviews/{$review->id}/vote", ['vote' => 'helpful']);

        $response->assertStatus(200);
        $this->assertEquals(1, $review->fresh()->helpful_votes);
    }

    public function test_vote_unhelpful_increments_unhelpful_votes(): void
    {
        $review = Review::factory()->create(['unhelpful_votes' => 0, 'approved' => true]);

        $response = $this->postJson("/reviews/{$review->id}/vote", ['vote' => 'unhelpful']);

        $response->assertStatus(200);
        $this->assertEquals(1, $review->fresh()->unhelpful_votes);
    }

    public function test_vote_returns_400_for_invalid_type(): void
    {
        $review = Review::factory()->create(['approved' => true]);

        $response = $this->postJson("/reviews/{$review->id}/vote", ['vote' => 'bogus']);

        $response->assertStatus(400);
    }

    public function test_vote_returns_404_for_missing_review(): void
    {
        $response = $this->postJson('/reviews/9999/vote', ['vote' => 'helpful']);

        $response->assertStatus(404);
    }

    public function test_repeated_vote_is_idempotent_and_switching_moves_one_vote(): void
    {
        $review = Review::factory()->create(['approved' => true]);
        foreach (['helpful', 'helpful', 'unhelpful'] as $vote) {
            $this->postJson("/reviews/{$review->id}/vote", ['vote' => $vote])->assertOk();
        }
        $this->assertSame(0, $review->fresh()->helpful_votes);
        $this->assertSame(1, $review->fresh()->unhelpful_votes);
        $this->assertDatabaseCount('review_votes', 1);
    }

    public function test_pending_reviews_cannot_be_voted_on_and_public_reviews_hide_email(): void
    {
        $review = Review::factory()->create(['approved' => false]);
        $this->postJson("/reviews/{$review->id}/vote", ['vote' => 'helpful'])->assertNotFound();
        $review->update(['approved' => true]);
        $this->getJson("/product/{$review->product_id}/reviews")->assertOk()->assertJsonMissingPath('0.user.email');
    }

    public function test_customers_cannot_approve_reviews(): void
    {
        $review = Review::factory()->create(['approved' => false]);
        $this->actingAs(User::factory()->create())->postJson('/reviews/approve/'.$review->id)->assertForbidden();
        $this->assertFalse((bool) $review->fresh()->approved);
    }
}
