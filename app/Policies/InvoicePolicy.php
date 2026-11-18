<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

/**
 * Authorizes access to Invoice models, restricting view/update/delete to users
 * belonging to the owning management group's user group (resolved through the
 * invoice's polymorphic costable).
 */
class InvoicePolicy
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
    public function view(User $user, Invoice $invoice): bool
    {
        $managementGroup = $invoice->managementGroup();

        return $managementGroup !== null && $user->groups->contains($managementGroup->user_group_id);
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
    public function update(User $user, Invoice $invoice): bool
    {
        return $this->view($user, $invoice);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Invoice $invoice): bool
    {
        return $this->view($user, $invoice);
    }
}
