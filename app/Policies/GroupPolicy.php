<?php

namespace App\Policies;

use App\Models\Group;
use App\Models\User;

/**
 * Authorizes access to Group models: membership grants view, admin role grants
 * update, and only the owner may delete the group. The Administrator (who sets up
 * user accounts and their groups) may view and manage every group.
 */
class GroupPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Group $group): bool
    {
        return $group->users->contains($user->id) || $user->isAdministrator();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model (manage membership).
     */
    public function update(User $user, Group $group): bool
    {
        return $user->isAdminOf($group) || $user->isAdministrator();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Group $group): bool
    {
        return $group->owner_id === $user->id;
    }
}
