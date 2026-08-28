<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StripePaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_payment_endpoint_rejects_missing_amount(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/stripe/payment', [
            'payment_method' => 'pm_test',
        ]);

        $response->assertStatus(404);
    }

    public function test_retired_payment_endpoint_rejects_client_amount(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/stripe/payment', [
            'amount' => 50.00,
        ]);

        $response->assertStatus(404);
    }

    public function test_retired_subscription_creation_is_unavailable(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/stripe/subscription', []);

        $response->assertStatus(404);
    }

    public function test_retired_subscription_update_is_unavailable(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patchJson('/stripe/subscription', [
            'plan' => 'premium',
        ]);

        $response->assertStatus(404);
    }

    public function test_retired_subscription_cancellation_is_unavailable(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->deleteJson('/stripe/subscription', []);

        $response->assertStatus(404);
    }
}
