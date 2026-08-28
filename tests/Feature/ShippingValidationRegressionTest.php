<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\ShippingMethods\Pages\CreateShippingMethod;
use App\Models\ShippingMethod;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ShippingValidationRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $staff = User::factory()->withPersonalTeam()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($staff->current_team_id);
        $staff->assignRole(Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']));
        $this->actingAs($staff);
    }

    private function payload(): array
    {
        return ['name' => 'Local flowers', 'base_rate' => '50.00', 'weight_rate' => '2.50',
            'max_weight' => '20.00', 'estimated_delivery_time' => 'Next day', 'is_active' => true];
    }

    public function test_shipping_writes_reject_values_that_exceed_database_precision(): void
    {
        $method = ShippingMethod::create($this->payload());
        foreach (['base_rate', 'weight_rate', 'max_weight'] as $field) {
            foreach (['1000000', '999999.999', '1e100', '-1', ['invalid']] as $value) {
                $payload = array_replace($this->payload(), [$field => $value]);
                $this->postJson('/shipping', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
                $this->putJson('/shipping/'.$method->id, $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
        $this->assertDatabaseCount('shipping_methods', 1);
        $this->assertSame(50.0, $method->fresh()->base_rate);
    }

    public function test_two_decimal_boundary_values_can_be_saved_without_overflow(): void
    {
        $this->post('/shipping', array_replace($this->payload(), ['base_rate' => '999999.99', 'weight_rate' => '0.01', 'max_weight' => '999999.99']))
            ->assertRedirect(route('shipping.index'));
        $this->assertDatabaseHas('shipping_methods', ['base_rate' => '999999.99', 'weight_rate' => '0.01']);
    }

    public function test_admin_form_rejects_silent_rounding_of_delivery_rates_and_weight(): void
    {
        // Focus on form validation; separate access tests cover resource permissions.
        $staffId = auth()->id();
        Gate::before(fn ($user) => $user->id === $staffId ? true : null);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(CreateShippingMethod::class)
            ->fillForm(array_replace($this->payload(), ['base_rate' => '0.001', 'weight_rate' => '0.001', 'max_weight' => '0.001']))
            ->call('create')->assertHasFormErrors(['base_rate', 'weight_rate', 'max_weight']);
        $this->assertDatabaseCount('shipping_methods', 0);
    }
}
