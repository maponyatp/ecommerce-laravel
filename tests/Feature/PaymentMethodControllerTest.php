<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentMethodControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_add_payment_method_creates_record(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $response = $this->postJson('/payment_methods/store', [
            'user_id' => $user->id,
            'name' => 'Visa',
            'details' => 'pm_test_4242',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('payment_methods', [
            'user_id' => $user->id,
            'name' => 'Visa',
        ]);
    }

    public function test_add_payment_method_requires_authentication(): void
    {
        auth()->logout();
        $response = $this->postJson('/payment_methods/store', [
            'name' => 'Visa',
            'details' => 'pm_test_4242',
        ]);

        $response->assertStatus(401);
    }

    public function test_add_payment_method_requires_name(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $response = $this->postJson('/payment_methods/store', [
            'user_id' => $user->id,
            'details' => 'pm_test_4242',
        ]);

        $response->assertStatus(422);
    }

    public function test_add_payment_method_requires_details(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $response = $this->postJson('/payment_methods/store', [
            'user_id' => $user->id,
            'name' => 'Visa',
        ]);

        $response->assertStatus(422);
    }

    public function test_edit_payment_method_updates_record(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);
        $pm = PaymentMethod::create([
            'user_id' => $user->id,
            'name' => 'Old Name',
            'details' => 'pm_test_0000',
        ]);

        $response = $this->postJson("/payment_methods/update/{$pm->id}", [
            'name' => 'New Name',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('New Name', $pm->fresh()->name);
    }

    public function test_edit_payment_method_returns_404_when_not_found(): void
    {
        $response = $this->postJson('/payment_methods/update/9999', [
            'name' => 'New Name',
        ]);

        $response->assertStatus(404);
    }

    public function test_delete_payment_method_removes_record(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);
        $pm = PaymentMethod::create([
            'user_id' => $user->id,
            'name' => 'Mastercard',
            'details' => 'pm_test_1111',
        ]);

        $response = $this->deleteJson("/payment_methods/destroy/{$pm->id}");

        $response->assertStatus(200);
        $this->assertNull(PaymentMethod::find($pm->id));
    }

    public function test_delete_payment_method_returns_404_when_not_found(): void
    {
        $response = $this->deleteJson('/payment_methods/destroy/9999');

        $response->assertStatus(404);
    }

    public function test_user_cannot_edit_someone_elses_reference_or_store_raw_card_details(): void
    {
        $other = User::factory()->create();
        $pm = PaymentMethod::create(['user_id' => $other->id, 'name' => 'Other', 'details' => 'pm_test_other']);
        $this->postJson('/payment_methods/update/'.$pm->id, ['name' => 'Changed'])->assertNotFound();
        $own = PaymentMethod::create(['user_id' => auth()->id(), 'name' => 'Own', 'details' => 'pm_test_own']);
        $this->postJson('/payment_methods/update/'.$own->id, ['details' => '4111111111111111'])->assertUnprocessable();
        $this->assertSame('pm_test_own', $own->fresh()->details);
    }
}
