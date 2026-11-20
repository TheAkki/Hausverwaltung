<?php

namespace App\Policies;

use App\Models\SettlementClaim;
use App\Models\User;

/**
 * Eine Forderung sehen und bearbeiten darf, wer in der Benutzergruppe ihrer
 * Verwaltungsgruppe ist (über Mietvertrag bzw. leerstehende Mieteinheit) – wie bei
 * Mietvertrag und Mieteinheit selbst.
 */
class SettlementClaimPolicy
{
    public function view(User $user, SettlementClaim $settlementClaim): bool
    {
        $managementGroup = $settlementClaim->managementGroup();

        return $managementGroup !== null && $user->groups->contains($managementGroup->user_group_id);
    }

    public function update(User $user, SettlementClaim $settlementClaim): bool
    {
        return $this->view($user, $settlementClaim);
    }
}
