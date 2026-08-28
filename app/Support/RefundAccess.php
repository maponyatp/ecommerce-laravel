<?php

namespace App\Support;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class RefundAccess
{
    public static function view(?User $actor): bool
    {
        return $actor && ! $actor->staff_access_disabled_at && $actor->hasAnyRole(['admin', 'super_admin'])
            && Gate::forUser($actor)->allows('viewAny', Order::class)
            && Gate::forUser($actor)->allows('view', new Order);
    }

    public static function manage(?User $actor, Order $order): bool
    {
        return self::view($actor) && Gate::forUser($actor)->allows('update', $order)
            && ($actor->hasRole('super_admin') || $actor->can('refund_order'));
    }
}
