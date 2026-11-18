<?php

namespace App\Policies;

use App\Models\CostCategory;
use App\Models\User;

/**
 * Authorizes access to CostCategory models. The built-in/"systemweiten" categories
 * (user_group_id null, from CostCategorySeeder) are visible to everyone but not
 * directly editable/deletable; self-created categories are restricted to members of
 * the Benutzergruppe they're assigned to.
 */
class CostCategoryPolicy
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
    public function view(User $user, CostCategory $costCategory): bool
    {
        return $costCategory->user_group_id === null || $user->groups->contains($costCategory->user_group_id);
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
     *
     * No "delete" ability exists (CostCategoryController has no destroy action) –
     * removing a Kostenkategorie would silently detach it from every ManagementGroup
     * that still references it via costs, so it isn't offered at all.
     */
    public function update(User $user, CostCategory $costCategory): bool
    {
        return $costCategory->user_group_id !== null && $user->groups->contains($costCategory->user_group_id);
    }
}
