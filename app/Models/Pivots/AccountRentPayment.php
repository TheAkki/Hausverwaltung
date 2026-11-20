<?php

namespace App\Models\Pivots;

use App\Models\RentPayment;
use App\Services\GroupOwnership\GroupOwnership;
use App\Services\GroupOwnership\ReferenceIntegrity;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Konto einer Mietzahlung (Kontensystem). Das Konto gehört zur Verwaltungsgruppe des
 * Mietvertrags (siehe ReferenceIntegrity).
 */
class AccountRentPayment extends Pivot
{
    protected $table = 'account_rent_payment';

    public $incrementing = true;

    protected static function booted(): void
    {
        static::saving(function (self $pivot): void {
            if (GroupOwnership::isSuspended()) {
                return;
            }

            $managementGroup = RentPayment::with('leaseInterval.lease.rentalUnit.managementUnit.managementGroup')
                ->find($pivot->rent_payment_id)
                ?->leaseInterval->lease->rentalUnit->managementUnit->managementGroup;

            ReferenceIntegrity::assertAccountBelongsTo($pivot->account_id, $managementGroup);
        });
    }
}
