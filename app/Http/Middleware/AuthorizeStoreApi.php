<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

class AuthorizeStoreApi
{
    public function handle(Request $request, Closure $next, string $area = 'catalog')
    {
        $user = $request->user();
        abort_unless($user, 401);
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->current_team_id);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        abort_unless($user->hasAnyRole(['admin', 'super_admin']), 403);
        $action = $request->route()->getActionMethod();
        $permission = $area === 'fulfillment'
            ? match ($action) {
                'placeOrder' => 'update_order',
                'trackOrder' => 'view_any_order',
                default => 'view_any_product',
            }
        : match ($action) {
            'index' => 'view_any_product', 'show' => 'view_product',
            'store' => 'create_product', 'destroy' => 'delete_product',
            default => 'update_product',
        };
        abort_unless($user->can($permission), 403);
        $write = in_array($action, ['store', 'update', 'destroy', 'addProducts', 'removeProducts', 'placeOrder'], true);
        abort_unless($user->tokenCan($area.($write ? ':write' : ':read')), 403);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
