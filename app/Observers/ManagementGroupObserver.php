<?php

namespace App\Observers;

use App\Models\BillingInterval;
use App\Models\ManagementGroup;
use Carbon\Carbon;

/**
 * Reacts to ManagementGroup "created" events by seeding the first one-year
 * BillingInterval starting from the group's billing_start (or January 1st).
 */
class ManagementGroupObserver
{
    /**
     * Handle the ManagementGroup "created" event: seed the first billing interval.
     */
    public function created(ManagementGroup $managementGroup): void
    {
        $start = $managementGroup->billing_start
            ? Carbon::parse($managementGroup->billing_start)
            : Carbon::createFromDate(now()->year, 1, 1);

        $end = (clone $start)->addYear()->subDay();

        BillingInterval::create([
            'management_group_id' => $managementGroup->id,
            'start_date' => $start,
            'end_date' => $end,
        ]);
    }
}
