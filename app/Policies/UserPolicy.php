<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool { return !$actor->staff_access_disabled_at && $actor->hasRole('super_admin'); }
    public function view(User $actor, User $user): bool { return $this->viewAny($actor); }
    public function create(User $actor): bool { return $this->viewAny($actor); }
    public function update(User $actor, User $user): bool { return $this->viewAny($actor); }
    public function delete(User $actor, User $user): bool { return false; }
    public function deleteAny(User $actor): bool { return false; }
}
