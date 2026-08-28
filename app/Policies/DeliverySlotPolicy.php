<?php

namespace App\Policies;

use App\Models\DeliverySlot;
use App\Models\User;

class DeliverySlotPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    public function view(User $user, DeliverySlot $slot): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, DeliverySlot $slot): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, DeliverySlot $slot): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
