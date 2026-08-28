<?php

namespace App\Policies;

use App\Models\Page;
use App\Models\User;

class PagePolicy
{
    private function allowed(User $user, string $permission): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin']) && ($user->hasRole('super_admin') || $user->can($permission));
    }

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'view_any_page');
    }

    public function view(User $user, Page $page): bool
    {
        return $this->allowed($user, 'view_page');
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'create_page');
    }

    public function update(User $user, Page $page): bool
    {
        return $this->allowed($user, 'update_page');
    }

    public function publish(User $user, Page $page): bool
    {
        return $this->allowed($user, 'publish_page');
    }

    public function delete(User $user, Page $page): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
