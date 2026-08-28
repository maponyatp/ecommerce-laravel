<?php

namespace App\Policies;

use App\Models\ShippingMethod;
use App\Models\User;

class ShippingMethodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    public function view(User $user, ShippingMethod $method): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ShippingMethod $method): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, ShippingMethod $method): bool
    {
        return false; // Deactivate methods; retain historical order references.
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
