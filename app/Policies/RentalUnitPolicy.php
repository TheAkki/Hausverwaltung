<?php

namespace App\Policies;

use App\Models\RentalUnit;
use App\Models\User;

/**
 * Authorizes access to RentalUnit models, restricting view/update/delete to users
 * belonging to the owning management group's user group.
 */
class RentalUnitPolicy
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
    public function view(User $user, RentalUnit $rentalUnit): bool
    {
        return $user->groups->contains($rentalUnit->managementUnit->managementGroup->user_group_id);
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
    public function update(User $user, RentalUnit $rentalUnit): bool
    {
        return $user->groups->contains($rentalUnit->managementUnit->managementGroup->user_group_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, RentalUnit $rentalUnit): bool
    {
        return $user->groups->contains($rentalUnit->managementUnit->managementGroup->user_group_id);
    }
}
