<?php

namespace Tests\Support;

use App\Models\User;
use Laravel\Sanctum\TransientToken;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

trait StoreApiStaff
{
    private function grantStoreApiStaff(User $user): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $user->assignRole(Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']));
        foreach (['view_any_product', 'view_product', 'create_product', 'update_product', 'delete_product', 'view_any_order', 'update_order'] as $name) {
            $user->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $user->withAccessToken(new TransientToken);
    }
}
