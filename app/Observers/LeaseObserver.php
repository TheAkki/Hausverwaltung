<?php

namespace App\Observers;

use App\Models\Lease;
use App\Models\LeaseInterval;

/**
 * Reacts to Lease "created" and "updated" events to keep the lease's LeaseInterval
 * records in sync with its start/end dates.
 */
class LeaseObserver
{
    /**
     * Handle the Lease "created" event: seed the first lease interval.
     */
    public function created(Lease $lease): void
    {
        LeaseInterval::create([
            'lease_id' => $lease->id,
            'start_date' => $lease->start_date,
            'end_date' => $lease->end_date,
            'rent_amount' => $lease->cold_rent,
            'occupant_count' => 1,
            'utility_advance_payment' => 0,
        ]);
    }

    /**
     * Handle the Lease "updated" event: propagate a new end date to the current interval.
     */
    public function updated(Lease $lease): void
    {
        if (! $lease->wasChanged('end_date') || $lease->end_date === null) {
            return;
        }

        $current = $lease->currentLeaseInterval();

        if ($current !== null) {
            $current->update(['end_date' => $lease->end_date]);
            $current->pruneOpenRentPaymentsBeyondEndDate();
        }
    }
}
