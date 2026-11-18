<?php

namespace App\Policies;

use App\Models\Lease;
use App\Models\User;

/**
 * Authorizes access to Lease models, restricting view/update/delete to users
 * belonging to the lease's rental unit's management group.
 */
class LeasePolicy
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
    public function view(User $user, Lease $lease): bool
    {
        return $user->groups->contains($lease->rentalUnit->managementUnit->managementGroup->user_group_id);
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
    public function update(User $user, Lease $lease): bool
    {
        return $user->groups->contains($lease->rentalUnit->managementUnit->managementGroup->user_group_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Lease $lease): bool
    {
        return $user->groups->contains($lease->rentalUnit->managementUnit->managementGroup->user_group_id);
    }
}
