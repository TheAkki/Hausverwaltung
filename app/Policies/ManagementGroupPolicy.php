<?php

namespace App\Policies;

use App\Models\ManagementGroup;
use App\Models\User;

/**
 * Authorizes access to ManagementGroup models, restricting view/update/delete to
 * users belonging to the group's user group.
 */
class ManagementGroupPolicy
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
    public function view(User $user, ManagementGroup $managementGroup): bool
    {
        return $user->groups->contains($managementGroup->user_group_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ManagementGroup $managementGroup): bool
    {
        return $user->groups->contains($managementGroup->user_group_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ManagementGroup $managementGroup): bool
    {
        return $user->groups->contains($managementGroup->user_group_id);
    }
}
