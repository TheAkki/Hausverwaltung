<?php

namespace App\Observers;

use App\Models\Tenant;
use App\Services\GroupOwnership\GroupOwnership;

/**
 * Ein Mieter darf nicht in eine andere Benutzergruppe wechseln, solange ihn ein
 * Mietvertrag einer Verwaltungsgruppe der bisherigen Gruppe verwendet (siehe
 * GroupOwnership).
 */
class TenantObserver
{
    public function updating(Tenant $tenant): void
    {
        if (GroupOwnership::isSuspended() || ! $tenant->isDirty('user_group_id')) {
            return;
        }

        foreach ($tenant->leases()->with('rentalUnit.managementUnit.managementGroup')->get() as $lease) {
            GroupOwnership::assertTenantFits($tenant, $lease->rentalUnit->managementUnit->managementGroup, GroupOwnership::FIELD_GROUP);
        }
    }
}
