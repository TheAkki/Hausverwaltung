<?php

namespace App\Policies;

use App\Models\AccountTransfer;
use App\Models\User;

/**
 * Eine Umbuchung sehen und bearbeiten darf, wer in der Benutzergruppe der
 * Verwaltungsgruppe ihrer Konten ist (beide Konten gehören zu derselben).
 */
class AccountTransferPolicy
{
    public function view(User $user, AccountTransfer $accountTransfer): bool
    {
        return $user->groups->contains($accountTransfer->fromAccount->managementGroup->user_group_id);
    }

    public function update(User $user, AccountTransfer $accountTransfer): bool
    {
        return $this->view($user, $accountTransfer);
    }
}
