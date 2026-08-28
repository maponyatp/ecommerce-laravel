<?php

namespace App\Policies;

use App\Models\OrderIssue;
use App\Models\User;

class OrderIssuePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    public function view(User $user, OrderIssue $issue): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, OrderIssue $issue): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, OrderIssue $issue): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
