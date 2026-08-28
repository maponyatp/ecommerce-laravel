<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RepairCatalogueAccess extends Command
{
    public const PERMISSIONS = ['view_any_product', 'view_product', 'create_product', 'update_product'];

    protected $signature = 'commerce:repair-catalogue-access {--apply : Add missing catalogue permissions to the existing global super_admin role}';

    protected $description = 'Preview or repair catalogue access without a global policy override or changes to ordinary staff';

    public function handle(): int
    {
        $registrar = app(PermissionRegistrar::class);
        $team = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(null);
        try {
            $role = Role::where('name', 'super_admin')->where('guard_name', 'web')
                ->when(config('permission.teams'), fn ($q) => $q->whereNull(config('permission.column_names.team_foreign_key', 'team_id')))->first();
            if (! $role) {
                $this->error('No existing global super_admin role. No account or role was created.');

                return self::FAILURE;
            }
            $missing = array_values(array_diff(self::PERMISSIONS, $role->permissions()->pluck('name')->all()));
            if ($this->option('apply') && $missing) {
                DB::transaction(function () use ($role, $missing) {
                    foreach ($missing as $permission) {
                        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
                    }
                });
                $registrar->forgetCachedPermissions();
                Log::info('Super-admin catalogue permissions repaired', ['role_id' => $role->id, 'added_permissions' => $missing]);
            }
            $this->line(json_encode(['mode' => $this->option('apply') ? 'apply' : 'preview', 'role_id' => $role->id,
                'missing_before' => $missing, 'added' => $this->option('apply') ? $missing : [],
                'users_promoted' => 0, 'other_roles_changed' => 0, 'policy_overrides_added' => 0], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } finally {
            $registrar->setPermissionsTeamId($team);
        }
    }
}
