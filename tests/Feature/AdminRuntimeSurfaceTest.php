<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminRuntimeSurfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_admin_listing_and_creation_pages_render_without_runtime_errors(): void
    {
        $staff = User::factory()->withPersonalTeam()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($staff->current_team_id);
        $staff->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
        $this->actingAs($staff);
        // This test checks rendering; dedicated feature tests check permission denials.
        Gate::before(fn ($user) => $user->id === $staff->id ? true : null);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Product::factory()->create();

        $failures = [];
        $checked = [];
        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();
            if (! in_array('GET', $route->methods(), true) || str_contains($uri, '{')
                || ! str_starts_with($route->getName() ?? '', 'filament.admin.')
                || in_array($uri, ['admin/login', 'admin/logout', 'admin/password-reset/request'], true)
                || str_contains($uri, 'password') || str_contains($uri, 'email-verification')) {
                continue;
            }
            $response = $this->get('/'.$uri);
            $checked[] = $uri;
            if ($response->status() >= 500) {
                $failures[$uri] = $response->exception ? get_class($response->exception).': '.strtok($response->exception->getMessage(), "\n") : $response->status();
            }
        }
        $this->assertGreaterThan(20, count($checked));
        $this->assertSame([], $failures, json_encode($failures, JSON_PRETTY_PRINT));
    }
}
