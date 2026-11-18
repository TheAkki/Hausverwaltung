<?php

namespace App\Policies;

use App\Models\InvoiceIssuer;
use App\Models\User;

/**
 * Authorizes access to InvoiceIssuer models, restricting view/update/delete to users
 * belonging to the issuer's owning Benutzergruppe (user_group_id).
 */
class InvoiceIssuerPolicy
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
    public function view(User $user, InvoiceIssuer $invoiceIssuer): bool
    {
        return $invoiceIssuer->user_group_id !== null && $user->groups->contains($invoiceIssuer->user_group_id);
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
    public function update(User $user, InvoiceIssuer $invoiceIssuer): bool
    {
        return $this->view($user, $invoiceIssuer);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, InvoiceIssuer $invoiceIssuer): bool
    {
        return $this->view($user, $invoiceIssuer);
    }
}
