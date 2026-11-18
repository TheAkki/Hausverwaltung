<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

/**
 * Authorizes access to Tenant models, restricting view/update/delete to users
 * belonging to the tenant's owning Benutzergruppe (user_group_id).
 */
class TenantPolicy
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
    public function view(User $user, Tenant $tenant): bool
    {
        return $tenant->user_group_id !== null && $user->groups->contains($tenant->user_group_id);
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
    public function update(User $user, Tenant $tenant): bool
    {
        return $this->view($user, $tenant);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Tenant $tenant): bool
    {
        return $this->view($user, $tenant);
    }
}
