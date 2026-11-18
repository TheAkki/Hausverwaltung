<?php

namespace App\Services;

use App\Models\BillingInterval;
use App\Models\Lease;
use App\Models\ManagementUnit;
use App\Models\RentalUnit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Resolves which Mietverträge a Betriebskostenabrechnung must cover for a given
 * ManagementUnit/BillingInterval: only leases of abrechnungsrelevante Mieteinheiten,
 * active at any point during the interval.
 *
 * "Vorverträge" (Lease::is_pre_contract) get no statement of their own: each real lease
 * absorbs the run of pre-contracts that immediately precede it on the same Mieteinheit –
 * its billed period is extended back over them and their Betriebskostenvorauszahlungen are
 * credited to it (Konzept.md "### Mietvertrag"/"### Mieteinheit").
 */
class BillingStatementLeaseResolver
{
    /**
     * @return Collection<int, array{
     *     lease: Lease, tenants: Collection, tenantNames: string, rentalUnit: RentalUnit,
     *     periodStart: Carbon, periodEnd: Carbon, days: int, currentColdRent: float,
     *     currentUtilityAdvance: float, monthlyUtilityAdvances: Collection<string, array{label: string, amount: float}>,
     *     totalUtilityAdvance: float, otherRents: Collection<int, array{rentalUnit: RentalUnit, coldRent: float, utilityAdvance: float}>
     * }>
     */
    public function forInterval(ManagementUnit $managementUnit, BillingInterval $billingInterval): Collection
    {
        $managementUnit->loadMissing('rentalUnits.leases.tenants', 'rentalUnits.leases.leaseIntervals');

        return $managementUnit->rentalUnits
            ->where('is_relevant_for_billing', true)
            ->flatMap(function ($rentalUnit) use ($billingInterval, $managementUnit) {
                $entries = collect();
                $pendingPreContracts = collect();

                foreach ($rentalUnit->leases->sortBy(fn (Lease $l) => $l->start_date->timestamp) as $lease) {
                    if ($lease->is_pre_contract) {
                        $pendingPreContracts->push($lease);

                        continue;
                    }

                    $entry = $this->buildEntry($lease, $pendingPreContracts, $rentalUnit, $billingInterval, $managementUnit);
                    $pendingPreContracts = collect();

                    if ($entry !== null) {
                        $entries->push($entry);
                    }
                }

                // Trailing Vorverträge without a following lease are invalid data (a Vorvertrag
                // must not be the last lease, see PreContractRequiresFollowingLease) and get dropped.
                return $entries;
            })->values();
    }

    /**
     * Builds one statement entry for a real lease, folding in the run of pre-contracts that
     * immediately precede it: the billed period is extended back over them and their
     * Betriebskostenvorauszahlungen are counted.
     *
     * @param  Collection<int, Lease>  $preContracts
     * @return array<string, mixed>|null
     */
    private function buildEntry(Lease $lease, Collection $preContracts, RentalUnit $rentalUnit, BillingInterval $billingInterval, ManagementUnit $managementUnit): ?array
    {
        $absorbedLeases = $preContracts->concat([$lease]);

        // Extend the effective start back over any absorbed Vorverträge (they precede $lease).
        $effectiveStart = $absorbedLeases
            ->sortBy(fn (Lease $l) => $l->start_date->timestamp)
            ->first()->start_date;

        $start = $effectiveStart->copy()->max($billingInterval->start_date);
        $end = ($lease->end_date ?? $billingInterval->end_date)->min($billingInterval->end_date);

        if ($start->gt($end)) {
            return null;
        }

        $current = $lease->currentLeaseInterval();
        $monthlyUtilityAdvances = $this->monthlyUtilityAdvances($absorbedLeases, $start, $end);

        return [
            'lease' => $lease,
            'tenants' => $lease->tenants,
            'tenantNames' => $lease->tenantNames(),
            'rentalUnit' => $rentalUnit,
            'periodStart' => $start,
            'periodEnd' => $end,
            'days' => $start->diffInDays($end) + 1,
            'currentColdRent' => (float) ($current->rent_amount ?? 0),
            'currentUtilityAdvance' => (float) ($current->utility_advance_payment ?? 0),
            'monthlyUtilityAdvances' => $monthlyUtilityAdvances,
            'totalUtilityAdvance' => $monthlyUtilityAdvances->sum('amount'),
            'otherRents' => $this->otherRents($managementUnit, $lease),
        ];
    }

    /**
     * Month-by-month Betriebskostenvorauszahlung (utility_advance_payment of whichever
     * LeaseInterval is active) for every calendar month overlapping [$start, $end],
     * keyed by "Y-m". Across an extended period, each month picks whichever of the
     * absorbed leases (a Vorvertrag or the main lease) is active that month.
     *
     * @param  Collection<int, Lease>  $leases
     * @return Collection<string, array{label: string, amount: float}>
     */
    private function monthlyUtilityAdvances(Collection $leases, Carbon $start, Carbon $end): Collection
    {
        $months = collect();
        $cursor = $start->copy()->startOfMonth();
        $lastMonth = $end->copy()->startOfMonth();

        while ($cursor->lte($lastMonth)) {
            $monthEnd = $cursor->copy()->endOfMonth();

            $lease = $leases->first(
                fn (Lease $l) => $l->start_date->lte($monthEnd) && ($l->end_date === null || $l->end_date->gte($cursor))
            );
            $interval = $lease?->leaseIntervals->first(
                fn ($li) => $li->start_date->lte($monthEnd) && ($li->end_date === null || $li->end_date->gte($cursor))
            );

            $months->put($cursor->format('Y-m'), [
                'label' => $cursor->copy()->locale('de')->translatedFormat('F Y'),
                'amount' => (float) ($interval->utility_advance_payment ?? 0),
            ]);

            $cursor->addMonthNoOverflow();
        }

        return $months;
    }

    /**
     * Rents this lease's tenant(s) currently pay for OTHER, nicht abrechnungsrelevante
     * Mieteinheiten in the same Verwaltungseinheit (e.g. Garage, Stellplatz) that this
     * Betriebskostenabrechnung doesn't itself cover, so a template can still list them
     * for a complete overview of what the tenant pays in total.
     *
     * @return Collection<int, array{rentalUnit: RentalUnit, coldRent: float, utilityAdvance: float}>
     */
    private function otherRents(ManagementUnit $managementUnit, Lease $lease): Collection
    {
        $tenantIds = $lease->tenants->pluck('id');
        $today = Carbon::today();

        return $managementUnit->rentalUnits
            ->where('is_relevant_for_billing', false)
            ->flatMap(function (RentalUnit $rentalUnit) use ($tenantIds, $today) {
                return $rentalUnit->leases
                    ->filter(fn (Lease $other) => $other->start_date->lte($today) && ($other->end_date === null || $other->end_date->gte($today)))
                    ->filter(fn (Lease $other) => $other->tenants->pluck('id')->intersect($tenantIds)->isNotEmpty())
                    ->map(function (Lease $other) use ($rentalUnit) {
                        $current = $other->currentLeaseInterval();

                        return [
                            'rentalUnit' => $rentalUnit,
                            'coldRent' => (float) ($current->rent_amount ?? 0),
                            'utilityAdvance' => (float) ($current->utility_advance_payment ?? 0),
                        ];
                    });
            })->values();
    }
}
