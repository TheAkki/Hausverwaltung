<?php

namespace App\Services\InvoiceSeries;

use App\Models\BillingInterval;
use App\Models\ManagementGroup;
use Carbon\Carbon;

/**
 * Findet das Abrechnungsintervall einer Verwaltungsgruppe, das ein Datum abdeckt, und
 * setzt die Folge der Jahresintervalle bei Bedarf fort – nach vorn wie nach hinten
 * (eine rückdatierte Rechnung braucht ggf. ein Intervall eines Vorjahres).
 */
final class BillingIntervalLocator
{
    public function covering(ManagementGroup $managementGroup, Carbon|string $date): BillingInterval
    {
        $date = Carbon::parse($date);

        // whereDate() statt where(): das automatisch angelegte Intervall
        // (ManagementGroupObserver) trägt eine Uhrzeit; ein roher Vergleich verfehlte es
        // genau an seinem Start- und Enddatum.
        $interval = $managementGroup->billingIntervals()
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();

        if ($interval !== null) {
            return $interval;
        }

        $first = $managementGroup->billingIntervals()->orderBy('start_date')->first();

        return $first !== null && $date->lt($first->start_date)
            ? $this->extendBackwards($managementGroup, $first, $date)
            : $this->extendForwards($managementGroup, $date);
    }

    private function extendBackwards(ManagementGroup $managementGroup, BillingInterval $cursor, Carbon $date): BillingInterval
    {
        while ($date->lt($cursor->start_date)) {
            $cursor = BillingInterval::create([
                'management_group_id' => $managementGroup->id,
                'start_date' => $cursor->start_date->copy()->subYear(),
                'end_date' => $cursor->start_date->copy()->subDay(),
            ]);
        }

        return $cursor;
    }

    private function extendForwards(ManagementGroup $managementGroup, Carbon $date): BillingInterval
    {
        $cursor = $managementGroup->billingIntervals()->orderBy('end_date', 'desc')->first();

        while ($cursor === null || $cursor->end_date->lt($date)) {
            $start = $cursor !== null ? $cursor->end_date->copy()->addDay() : Carbon::createFromDate($date->year, 1, 1);

            $cursor = BillingInterval::create([
                'management_group_id' => $managementGroup->id,
                'start_date' => $start,
                'end_date' => Carbon::createFromDate($start->year, 12, 31),
            ]);
        }

        return $cursor;
    }
}
