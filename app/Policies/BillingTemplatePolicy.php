<?php

namespace App\Policies;

use App\Models\BillingTemplate;
use App\Models\User;

/**
 * Authorizes access to BillingTemplate models. The built-in/"internal" templates
 * (user_group_id null) are visible to and duplicable by everyone but not directly
 * editable/deletable; self-created templates are restricted to members of the
 * Benutzergruppe they're assigned to.
 */
class BillingTemplatePolicy
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
    public function view(User $user, BillingTemplate $billingTemplate): bool
    {
        return $billingTemplate->user_group_id === null || $user->groups->contains($billingTemplate->user_group_id);
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
    public function update(User $user, BillingTemplate $billingTemplate): bool
    {
        return $billingTemplate->user_group_id !== null && $user->groups->contains($billingTemplate->user_group_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, BillingTemplate $billingTemplate): bool
    {
        return $billingTemplate->user_group_id !== null && $user->groups->contains($billingTemplate->user_group_id);
    }
}
