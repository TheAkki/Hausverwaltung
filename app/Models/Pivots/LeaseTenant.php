<?php

namespace App\Models\Pivots;

use App\Models\Lease;
use App\Models\Tenant;
use App\Services\GroupOwnership\GroupOwnership;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Zuordnung Mietvertrag–Mieter. Ein Mieter gehört zur Benutzergruppe der
 * Verwaltungsgruppe des Mietvertrags (siehe GroupOwnership).
 */
class LeaseTenant extends Pivot
{
    protected $table = 'lease_tenant';

    public $incrementing = true;

    protected static function booted(): void
    {
        static::saving(function (self $pivot): void {
            if (GroupOwnership::isSuspended()) {
                return;
            }

            $tenant = Tenant::find($pivot->tenant_id);
            $lease = Lease::with('rentalUnit.managementUnit.managementGroup')->find($pivot->lease_id);

            if ($tenant !== null && $lease !== null) {
                GroupOwnership::assertTenantFits($tenant, $lease->rentalUnit->managementUnit->managementGroup);
            }
        });
    }
}
