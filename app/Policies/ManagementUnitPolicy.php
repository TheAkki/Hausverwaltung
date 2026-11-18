<?php

namespace App\Policies;

use App\Models\ManagementUnit;
use App\Models\User;

/**
 * Authorizes access to ManagementUnit models, restricting view/update/delete to
 * users belonging to the owning management group's user group.
 */
class ManagementUnitPolicy
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
    public function view(User $user, ManagementUnit $managementUnit): bool
    {
        return $user->groups->contains($managementUnit->managementGroup->user_group_id);
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
    public function update(User $user, ManagementUnit $managementUnit): bool
    {
        return $user->groups->contains($managementUnit->managementGroup->user_group_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ManagementUnit $managementUnit): bool
    {
        return $user->groups->contains($managementUnit->managementGroup->user_group_id);
    }
}
