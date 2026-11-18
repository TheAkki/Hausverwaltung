<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Cost;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\RentalUnit;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds year-based financial overview matrices (rent income by lease/rental-unit
 * type and expenses by cost category) aggregated per month.
 */
class FinancialOverviewService
{
    /**
     * Build a Mieteinheit x month occupancy matrix (Belegungsmatrix) for the given
     * year: every rental unit gets a row, even if vacant all year, and each month's
     * cell holds the occupying tenant names or null when vacant.
     *
     * @param  Collection<int, RentalUnit>  $rentalUnits
     * @return array<int, array{rental_unit: string, months: array<int, ?string>}>
     */
    public function occupancyMatrix(Collection $rentalUnits, int $year): array
    {
        $rows = [];

        foreach ($rentalUnits as $rentalUnit) {
            $months = [];

            for ($month = 1; $month <= 12; $month++) {
                $monthStart = Carbon::create($year, $month, 1)->startOfDay();
                $monthEnd = $monthStart->copy()->endOfMonth();

                $lease = $rentalUnit->leases->first(
                    fn (Lease $lease) => $lease->start_date->lte($monthEnd)
                        && ($lease->end_date === null || $lease->end_date->gte($monthStart))
                );

                $months[$month] = $lease?->tenantNames();
            }

            $rows[] = [
                'rental_unit' => $rentalUnit->label(),
                'months' => $months,
            ];
        }

        return $rows;
    }

    /**
     * Build a Mieteinheit x month occupancy plan (Belegungsplan) for the given year:
     * each cell holds the occupant count (Personen) of whichever LeaseInterval, across
     * any of the rental unit's Leases, is active during that month (0 if vacant).
     * Rental units whose yearly sum is 0 (vacant the whole year) are omitted.
     *
     * @param  Collection<int, RentalUnit>  $rentalUnits
     * @return array{rows: array<int, array{rental_unit: string, months: array<int, int>, total: int}>, totals: array<int, int>, grandTotal: int}
     */
    public function occupancyPlan(Collection $rentalUnits, int $year): array
    {
        $rows = [];
        $totals = array_fill(1, 12, 0);

        foreach ($rentalUnits as $rentalUnit) {
            $intervals = $rentalUnit->leases->flatMap->leaseIntervals;
            $months = [];

            for ($month = 1; $month <= 12; $month++) {
                $monthStart = Carbon::create($year, $month, 1)->startOfDay();
                $monthEnd = $monthStart->copy()->endOfMonth();

                $interval = $intervals->first(
                    fn ($li) => $li->start_date->lte($monthEnd) && ($li->end_date === null || $li->end_date->gte($monthStart))
                );

                $months[$month] = (int) ($interval->occupant_count ?? 0);
            }

            $total = array_sum($months);

            if ($total === 0) {
                continue;
            }

            foreach ($months as $month => $count) {
                $totals[$month] += $count;
            }

            $rows[] = [
                'rental_unit' => $rentalUnit->label(),
                'months' => $months,
                'total' => $total,
            ];
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'grandTotal' => array_sum($totals),
        ];
    }

    /**
     * Sums a rental-unit set's Personenmonate (occupant-months) over an arbitrary date
     * range: for every calendar month overlapping [$start, $end], the occupant_count of
     * whichever LeaseInterval is active during that month (0 if vacant), summed across
     * all given rental units. Mirrors occupancyPlan()'s month-by-month "active interval"
     * lookup, but over an arbitrary range instead of a fixed calendar year, and
     * collapsed to a single total instead of a month-by-month matrix – used for the
     * Betriebskostenabrechnung's "Summe der Personenmonate" template variable.
     *
     * @param  Collection<int, RentalUnit>  $rentalUnits
     */
    public function personMonths(Collection $rentalUnits, Carbon $start, Carbon $end): int
    {
        $total = 0;
        $cursor = $start->copy()->startOfMonth();
        $lastMonth = $end->copy()->startOfMonth();

        while ($cursor->lte($lastMonth)) {
            $monthEnd = $cursor->copy()->endOfMonth();

            foreach ($rentalUnits as $rentalUnit) {
                $intervals = $rentalUnit->leases->flatMap->leaseIntervals;

                $interval = $intervals->first(
                    fn ($li) => $li->start_date->lte($monthEnd) && ($li->end_date === null || $li->end_date->gte($cursor))
                );

                $total += (int) ($interval->occupant_count ?? 0);
            }

            $cursor->addMonthNoOverflow();
        }

        return $total;
    }

    /**
     * Sums a single Lease's OWN Personenmonate (occupant-months) over an arbitrary
     * date range – the "PM (Mieter)" figure from the Betriebskosten-Berechnung, as
     * opposed to personMonths()'s whole-building/whole-Verwaltungsgruppe total. Same
     * month-by-month "active LeaseInterval" lookup as personMonths(), but scoped to
     * this one lease's own LeaseIntervals instead of every lease of a rental-unit set.
     */
    public function personMonthsForLease(Lease $lease, Carbon $start, Carbon $end): int
    {
        $total = 0;
        $cursor = $start->copy()->startOfMonth();
        $lastMonth = $end->copy()->startOfMonth();

        while ($cursor->lte($lastMonth)) {
            $monthEnd = $cursor->copy()->endOfMonth();

            $interval = $lease->leaseIntervals->first(
                fn ($li) => $li->start_date->lte($monthEnd) && ($li->end_date === null || $li->end_date->gte($cursor))
            );

            $total += (int) ($interval->occupant_count ?? 0);

            $cursor->addMonthNoOverflow();
        }

        return $total;
    }

    /**
     * Build a tenant/rental-unit x month income matrix for the given year. Leases are
     * grouped by (Mieter, Mieteinheit): a tenant re-signing a new Mietvertrag for the
     * same Mieteinheit (e.g. after a Vorvertrag, or a renewal) still lands in a single
     * row instead of one row per Lease, as long as both the tenant(s) and the
     * Mieteinheit match – mirroring incomeMatrixByTenant()'s per-tenant grouping, just
     * keyed by tenant+Mieteinheit instead of tenant alone.
     *
     * @param  Collection<int, Lease>  $leases
     * @return array{rows: array<int, array{tenant: string, rental_unit: string, months: array<int, float>, total: float}>, totals: array<int, float>, grandTotal: float}
     */
    public function incomeMatrix(Collection $leases, int $year): array
    {
        $rows = [];
        $totals = array_fill(1, 12, 0.0);

        foreach ($leases as $lease) {
            $months = $this->activeLeaseMonthlyAmounts($lease, $year);

            if ($months === null) {
                continue;
            }

            $tenant = $lease->tenantNames();
            $rentalUnit = $lease->rentalUnit->label();
            $key = $tenant.'|'.$rentalUnit;

            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'tenant' => $tenant,
                    'rental_unit' => $rentalUnit,
                    'months' => array_fill(1, 12, 0.0),
                    'total' => 0.0,
                ];
            }

            foreach ($months as $month => $amount) {
                $rows[$key]['months'][$month] += $amount;
                $totals[$month] += $amount;
            }

            $rows[$key]['total'] += array_sum($months);
        }

        return [
            'rows' => array_values($rows),
            'totals' => $totals,
            'grandTotal' => array_sum($totals),
        ];
    }

    /**
     * Build a tenant x month income matrix for the given year, aggregating every
     * lease of a tenant (across all of their rental units) into a single row.
     *
     * @param  Collection<int, Lease>  $leases
     * @return array{rows: array<int, array{tenant: string, months: array<int, float>, total: float}>, totals: array<int, float>, grandTotal: float}
     */
    public function incomeMatrixByTenant(Collection $leases, int $year): array
    {
        $rows = [];
        $totals = array_fill(1, 12, 0.0);

        foreach ($leases as $lease) {
            $months = $this->activeLeaseMonthlyAmounts($lease, $year);

            if ($months === null) {
                continue;
            }

            $tenantKey = $lease->tenantNames();

            if (! isset($rows[$tenantKey])) {
                $rows[$tenantKey] = [
                    'tenant' => $tenantKey,
                    'months' => array_fill(1, 12, 0.0),
                    'total' => 0.0,
                ];
            }

            foreach ($months as $month => $amount) {
                $rows[$tenantKey]['months'][$month] += $amount;
                $totals[$month] += $amount;
            }

            $rows[$tenantKey]['total'] += array_sum($months);
        }

        return [
            'rows' => array_values($rows),
            'totals' => $totals,
            'grandTotal' => array_sum($totals),
        ];
    }

    /**
     * Build a tenant x month income matrix for the given year, aggregating every
     * lease of a tenant but keeping Kaltmiete and Nebenkosten as separate figures
     * per month instead of a combined total.
     *
     * @param  Collection<int, Lease>  $leases
     * @return array{
     *     rows: array<int, array{tenant: string, months: array<int, array{rent: float, utility: float}>, totalRent: float, totalUtility: float}>,
     *     totals: array<int, array{rent: float, utility: float}>,
     *     grandTotal: float
     * }
     */
    public function incomeMatrixByTenantSplit(Collection $leases, int $year): array
    {
        $rows = [];
        $totals = array_fill(1, 12, ['rent' => 0.0, 'utility' => 0.0]);

        foreach ($leases as $lease) {
            $months = $this->activeLeaseMonthlyBreakdown($lease, $year);

            if ($months === null) {
                continue;
            }

            $tenantKey = $lease->tenantNames();

            if (! isset($rows[$tenantKey])) {
                $rows[$tenantKey] = [
                    'tenant' => $tenantKey,
                    'months' => array_fill(1, 12, ['rent' => 0.0, 'utility' => 0.0]),
                    'totalRent' => 0.0,
                    'totalUtility' => 0.0,
                ];
            }

            foreach ($months as $month => $amounts) {
                $rows[$tenantKey]['months'][$month]['rent'] += $amounts['rent'];
                $rows[$tenantKey]['months'][$month]['utility'] += $amounts['utility'];
                $totals[$month]['rent'] += $amounts['rent'];
                $totals[$month]['utility'] += $amounts['utility'];
            }

            $rows[$tenantKey]['totalRent'] += array_sum(array_column($months, 'rent'));
            $rows[$tenantKey]['totalUtility'] += array_sum(array_column($months, 'utility'));
        }

        $grandTotal = array_sum(array_map(fn ($m) => $m['rent'] + $m['utility'], $totals));

        return [
            'rows' => array_values($rows),
            'totals' => $totals,
            'grandTotal' => $grandTotal,
        ];
    }

    /**
     * Group monthly rent income by rental-unit type for the given year.
     *
     * @param  Collection<int, Lease>  $leases
     * @return array{types: array<string, array{months: array<int, float>, total: float}>, totals: array<int, float>, grandTotal: float}
     */
    public function incomeByRentalUnitType(Collection $leases, int $year): array
    {
        $types = [];
        $totals = array_fill(1, 12, 0.0);

        foreach ($leases as $lease) {
            $months = $this->activeLeaseMonthlyAmounts($lease, $year);

            if ($months === null) {
                continue;
            }

            $type = $lease->rentalUnit->type;

            if (! isset($types[$type])) {
                $types[$type] = ['months' => array_fill(1, 12, 0.0), 'total' => 0.0];
            }

            foreach ($months as $month => $amount) {
                $types[$type]['months'][$month] += $amount;
                $totals[$month] += $amount;
            }

            $types[$type]['total'] += array_sum($months);
        }

        return [
            'types' => $types,
            'totals' => $totals,
            'grandTotal' => array_sum($totals),
        ];
    }

    /**
     * Build a tenant/rental-unit x month matrix of actually booked rent income
     * (RentPayment rows with a Buchungsdatum), for the given year. Mirrors
     * incomeMatrix()'s row shape/inclusion rules (including the tenant+Mieteinheit
     * grouping), but sums confirmed payments instead of the expected LeaseInterval
     * amount.
     *
     * @param  Collection<int, Lease>  $leases
     * @return array{rows: array<int, array{tenant: string, rental_unit: string, months: array<int, float>, total: float}>, totals: array<int, float>, grandTotal: float}
     */
    public function bookedIncomeMatrix(Collection $leases, int $year): array
    {
        $rows = [];
        $totals = array_fill(1, 12, 0.0);

        foreach ($leases as $lease) {
            if ($this->activeLeaseMonthlyAmounts($lease, $year) === null) {
                continue;
            }

            $months = $this->bookedLeaseMonthlyAmounts($lease, $year);

            $tenant = $lease->tenantNames();
            $rentalUnit = $lease->rentalUnit->label();
            $key = $tenant.'|'.$rentalUnit;

            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'tenant' => $tenant,
                    'rental_unit' => $rentalUnit,
                    'months' => array_fill(1, 12, 0.0),
                    'total' => 0.0,
                ];
            }

            foreach ($months as $month => $amount) {
                $rows[$key]['months'][$month] += $amount;
                $totals[$month] += $amount;
            }

            $rows[$key]['total'] += array_sum($months);
        }

        return [
            'rows' => array_values($rows),
            'totals' => $totals,
            'grandTotal' => array_sum($totals),
        ];
    }

    /**
     * Same as bookedIncomeMatrix(), but grouped by rental-unit type, mirroring
     * incomeByRentalUnitType().
     *
     * @param  Collection<int, Lease>  $leases
     * @return array{types: array<string, array{months: array<int, float>, total: float}>, totals: array<int, float>, grandTotal: float}
     */
    public function bookedIncomeByRentalUnitType(Collection $leases, int $year): array
    {
        $types = [];
        $totals = array_fill(1, 12, 0.0);

        foreach ($leases as $lease) {
            if ($this->activeLeaseMonthlyAmounts($lease, $year) === null) {
                continue;
            }

            $months = $this->bookedLeaseMonthlyAmounts($lease, $year);
            $type = $lease->rentalUnit->type;

            if (! isset($types[$type])) {
                $types[$type] = ['months' => array_fill(1, 12, 0.0), 'total' => 0.0];
            }

            foreach ($months as $month => $amount) {
                $types[$type]['months'][$month] += $amount;
                $totals[$month] += $amount;
            }

            $types[$type]['total'] += array_sum($months);
        }

        return [
            'types' => $types,
            'totals' => $totals,
            'grandTotal' => array_sum($totals),
        ];
    }

    /**
     * Same as bookedIncomeMatrix(), but aggregated by tenant across all of their
     * leases, mirroring incomeMatrixByTenant().
     *
     * @param  Collection<int, Lease>  $leases
     * @return array{rows: array<int, array{tenant: string, months: array<int, float>, total: float}>, totals: array<int, float>, grandTotal: float}
     */
    public function bookedIncomeMatrixByTenant(Collection $leases, int $year): array
    {
        $rows = [];
        $totals = array_fill(1, 12, 0.0);

        foreach ($leases as $lease) {
            if ($this->activeLeaseMonthlyAmounts($lease, $year) === null) {
                continue;
            }

            $months = $this->bookedLeaseMonthlyAmounts($lease, $year);
            $tenantKey = $lease->tenantNames();

            if (! isset($rows[$tenantKey])) {
                $rows[$tenantKey] = [
                    'tenant' => $tenantKey,
                    'months' => array_fill(1, 12, 0.0),
                    'total' => 0.0,
                ];
            }

            foreach ($months as $month => $amount) {
                $rows[$tenantKey]['months'][$month] += $amount;
                $totals[$month] += $amount;
            }

            $rows[$tenantKey]['total'] += array_sum($months);
        }

        return [
            'rows' => array_values($rows),
            'totals' => $totals,
            'grandTotal' => array_sum($totals),
        ];
    }

    /**
     * Same as bookedIncomeMatrixByTenant(), but keeping the booked Kaltmiete and
     * Nebenkosten portions separate, mirroring incomeMatrixByTenantSplit().
     *
     * @param  Collection<int, Lease>  $leases
     * @return array{
     *     rows: array<int, array{tenant: string, months: array<int, array{rent: float, utility: float}>, totalRent: float, totalUtility: float}>,
     *     totals: array<int, array{rent: float, utility: float}>,
     *     grandTotal: float
     * }
     */
    public function bookedIncomeMatrixByTenantSplit(Collection $leases, int $year): array
    {
        $rows = [];
        $totals = array_fill(1, 12, ['rent' => 0.0, 'utility' => 0.0]);

        foreach ($leases as $lease) {
            if ($this->activeLeaseMonthlyAmounts($lease, $year) === null) {
                continue;
            }

            $months = $this->bookedLeaseMonthlyBreakdown($lease, $year);
            $tenantKey = $lease->tenantNames();

            if (! isset($rows[$tenantKey])) {
                $rows[$tenantKey] = [
                    'tenant' => $tenantKey,
                    'months' => array_fill(1, 12, ['rent' => 0.0, 'utility' => 0.0]),
                    'totalRent' => 0.0,
                    'totalUtility' => 0.0,
                ];
            }

            foreach ($months as $month => $amounts) {
                $rows[$tenantKey]['months'][$month]['rent'] += $amounts['rent'];
                $rows[$tenantKey]['months'][$month]['utility'] += $amounts['utility'];
                $totals[$month]['rent'] += $amounts['rent'];
                $totals[$month]['utility'] += $amounts['utility'];
            }

            $rows[$tenantKey]['totalRent'] += array_sum(array_column($months, 'rent'));
            $rows[$tenantKey]['totalUtility'] += array_sum(array_column($months, 'utility'));
        }

        $grandTotal = array_sum(array_map(fn ($m) => $m['rent'] + $m['utility'], $totals));

        return [
            'rows' => array_values($rows),
            'totals' => $totals,
            'grandTotal' => $grandTotal,
        ];
    }

    /**
     * Builds the Steuerübersicht's Mieteinnahmen-Pivot: gebuchte Mieteinnahmen
     * (Kaltmiete) und Betriebskostenvorauszahlungen je Mietvertrag für das gegebene
     * Jahr, gruppiert nach der Etage der jeweiligen Mieteinheit (Erdgeschoss, 1./2./3.
     * OG, >3. OG), zusammen mit der Summe der in diesem Jahr gestellten
     * Betriebskostennachzahlungen/-rückzahlungen (SettlementClaim, positiv =
     * Nachzahlung, negativ = Rückzahlung) je Mietvertrag. Erwartet
     * leaseIntervals.rentPayments und settlementClaims bereits geladen.
     *
     * @param  Collection<int, Lease>  $leases
     * @return array{
     *     floors: array<string, array{
     *         label: string, rent: float, utility: float, settlement: float,
     *         leases: array<int, array{label: string, rent: float, utility: float, settlement: float}>
     *     }>,
     *     totals: array{rent: float, utility: float, settlement: float}
     * }
     */
    public function taxOverviewIncomeByFloor(Collection $leases, int $year): array
    {
        $floors = [];
        $totals = ['rent' => 0.0, 'utility' => 0.0, 'settlement' => 0.0];

        // Konzept.md "### Mietvertrag": a Mietvertrag coupled to another one via
        // "Mietvertrag: <ref>" (e.g. a Stellplatz coupled to the tenant's Wohnung) is
        // never listed as its own row – its Mieteinnahmen/Nebenkosten/Nach-/Rückzahlungen
        // are summed into the referenced Mietvertrag instead. Amounts are collected per
        // lease first so a coupled lease's amounts can be folded into its target's before
        // the floor buckets (which use the TARGET's rentalUnit/Etage) are built.
        $amountsByLeaseId = [];

        foreach ($leases as $lease) {
            // Zuflussprinzip (§ 11 EStG): the Steuerübersicht buckets every payment by
            // the year it was actually BOOKED (received), not the year its rent period
            // nominally falls in – see taxRelevantLeaseMonthlyBreakdown(). A
            // Betriebskostennachzahlung/-rückzahlung can be booked well after the
            // Mietvertrag itself already ended (e.g. the Nachzahlung is only settled
            // once the Mieter has already moved out) and must still count toward the
            // year it was booked – so a Mietvertrag not "aktiv" (rent-wise) during
            // $year is NOT skipped outright, only its rent/Nebenkosten default to 0.
            $months = $this->activeLeaseMonthlyAmounts($lease, $year) !== null
                ? $this->taxRelevantLeaseMonthlyBreakdown($lease, $year)
                : [];

            $rent = array_sum(array_column($months, 'rent'));
            $utility = array_sum(array_column($months, 'utility'));
            $settlement = (float) $lease->settlementClaims
                ->filter(fn ($claim) => $claim->booking_date !== null && $claim->booking_date->year === $year)
                ->sum('amount');

            if ($months === [] && $settlement === 0.0) {
                continue;
            }

            $amountsByLeaseId[$lease->id] = [
                'lease' => $lease,
                'periodStart' => $lease->start_date,
                'rent' => $rent,
                'utility' => $utility,
                'settlement' => $settlement,
            ];
        }

        // Konzept.md "### Mietvertrag"/BillingStatementLeaseResolver: a Vorvertrag gets
        // no row of its own – the real Mietvertrag immediately following it on the same
        // Mieteinheit absorbs its Mieteinnahmen/Nebenkosten/Nach-/Rückzahlungen, and the
        // displayed period is extended back over it.
        foreach ($amountsByLeaseId as $leaseId => $entry) {
            if (! $entry['lease']->is_pre_contract) {
                continue;
            }

            $successor = $entry['lease']->successorLease();

            // Falls back to listing the Vorvertrag on its own when its successor isn't
            // (also) active this year, rather than silently dropping its income.
            if ($successor === null || ! isset($amountsByLeaseId[$successor->id])) {
                continue;
            }

            $amountsByLeaseId[$successor->id]['rent'] += $entry['rent'];
            $amountsByLeaseId[$successor->id]['utility'] += $entry['utility'];
            $amountsByLeaseId[$successor->id]['settlement'] += $entry['settlement'];
            $amountsByLeaseId[$successor->id]['periodStart'] = $entry['periodStart']->min($amountsByLeaseId[$successor->id]['periodStart']);
            unset($amountsByLeaseId[$leaseId]);
        }

        foreach ($amountsByLeaseId as $leaseId => $entry) {
            $targetId = $entry['lease']->coupled_to_lease_id;

            // Falls back to listing the coupled lease on its own when its target isn't
            // (also) active this year, rather than silently dropping its income.
            if ($targetId === null || ! isset($amountsByLeaseId[$targetId])) {
                continue;
            }

            $amountsByLeaseId[$targetId]['rent'] += $entry['rent'];
            $amountsByLeaseId[$targetId]['utility'] += $entry['utility'];
            $amountsByLeaseId[$targetId]['settlement'] += $entry['settlement'];
            unset($amountsByLeaseId[$leaseId]);
        }

        foreach ($amountsByLeaseId as $entry) {
            $lease = $entry['lease'];
            [$floorKey, $floorLabel] = $this->floorBucket($lease->rentalUnit->floor);

            if (! isset($floors[$floorKey])) {
                $floors[$floorKey] = [
                    'label' => $floorLabel, 'rent' => 0.0, 'utility' => 0.0, 'settlement' => 0.0, 'leases' => [],
                ];
            }

            $floors[$floorKey]['rent'] += $entry['rent'];
            $floors[$floorKey]['utility'] += $entry['utility'];
            $floors[$floorKey]['settlement'] += $entry['settlement'];
            $floors[$floorKey]['leases'][] = [
                'label' => $this->leasePeriodLabel($lease, $year, $entry['periodStart']),
                'rent' => $entry['rent'],
                'utility' => $entry['utility'],
                'settlement' => $entry['settlement'],
            ];

            $totals['rent'] += $entry['rent'];
            $totals['utility'] += $entry['utility'];
            $totals['settlement'] += $entry['settlement'];
        }

        uksort($floors, fn ($a, $b) => $this->floorSortOrder($a) <=> $this->floorSortOrder($b));

        return ['floors' => $floors, 'totals' => $totals];
    }

    /**
     * Buckets a Mieteinheit's Etage into the Steuerübersicht's fixed groups (0, 1, 2,
     * 3, >3), plus a catch-all for units without an Etage.
     *
     * @return array{0: string, 1: string} [key, label]
     */
    private function floorBucket(?int $floor): array
    {
        return match (true) {
            $floor === null => ['none', __('Ohne Etage')],
            $floor === 0 => ['0', __('Erdgeschoss')],
            $floor >= 1 && $floor <= 3 => [(string) $floor, __(':floor. OG', ['floor' => $floor])],
            default => ['4+', __('>3. OG')],
        };
    }

    private function floorSortOrder(string $key): int
    {
        return match ($key) {
            'none' => 100,
            '4+' => 4,
            default => (int) $key,
        };
    }

    /**
     * "Mietvertrag <Mietername> – <Mieteinheit> (<Zeitraum innerhalb des Jahres>)",
     * für die aufgeklappte Mietvertrags-Zeile der Steuerübersicht. $periodStart
     * overrides $lease->start_date for a Mietvertrag that absorbed an immediately
     * preceding Vorvertrag (Konzept.md "### Mietvertrag") – the displayed period then
     * extends back over it, matching BillingStatementLeaseResolver's "effektiver
     * Beginn" for Betriebskostenabrechnungen.
     */
    private function leasePeriodLabel(Lease $lease, int $year, ?Carbon $periodStart = null): string
    {
        $yearStart = Carbon::create($year, 1, 1);
        $yearEnd = Carbon::create($year, 12, 31);
        $start = ($periodStart ?? $lease->start_date)->copy()->max($yearStart);
        $end = ($lease->end_date ?? $yearEnd)->copy()->min($yearEnd);

        // The Mietvertrag itself may not have been active at all during $year (e.g. a
        // Nachzahlung only gets booked well after the Mieter already moved out) –
        // clamping to $year would then produce a start AFTER the end. Show the
        // Mietvertrag's real period instead of a nonsensical range in that case.
        if ($start->gt($end)) {
            $start = ($periodStart ?? $lease->start_date)->copy();
            $end = ($lease->end_date ?? $yearEnd)->copy();
        }

        $period = match (true) {
            $start->equalTo($yearStart) && $end->equalTo($yearEnd) => (string) $year,
            // Same-year partial period: the year need only appear once (at the end).
            $start->year === $end->year => "{$start->format('d.m.')}–{$end->format('d.m.Y')}",
            // Cross-year period (the "Mietvertrag war während $year gar nicht aktiv"
            // fallback above): both ends need their own year to stay unambiguous.
            default => "{$start->format('d.m.Y')}–{$end->format('d.m.Y')}",
        };

        return "{$lease->tenantNames()} – {$lease->rentalUnit->description} ({$period})";
    }

    /**
     * Sums a lease's RentPayment rows that have a Buchungsdatum, by the month of
     * their period_date, for the given year. Requires leaseIntervals.rentPayments
     * to already be loaded.
     *
     * @return array<int, float>
     */
    private function bookedLeaseMonthlyAmounts(Lease $lease, int $year): array
    {
        $months = array_fill(1, 12, 0.0);

        foreach ($lease->leaseIntervals as $interval) {
            foreach ($interval->rentPayments as $payment) {
                if ($payment->booking_date === null || $payment->period_date->year !== $year) {
                    continue;
                }

                $months[$payment->period_date->month] += (float) $payment->amount;
            }
        }

        return $months;
    }

    /**
     * Same as bookedLeaseMonthlyAmounts(), but keeping the booked Kaltmiete and
     * Nebenkosten portions separate. Falls back to treating the whole booked amount
     * as rent (with 0 utility) for RentPayment rows created before the rent/utility
     * split columns existed.
     *
     * @return array<int, array{rent: float, utility: float}>
     */
    private function bookedLeaseMonthlyBreakdown(Lease $lease, int $year): array
    {
        $months = array_fill(1, 12, ['rent' => 0.0, 'utility' => 0.0]);

        foreach ($lease->leaseIntervals as $interval) {
            foreach ($interval->rentPayments as $payment) {
                if ($payment->booking_date === null || $payment->period_date->year !== $year) {
                    continue;
                }

                $month = $payment->period_date->month;
                $months[$month]['rent'] += (float) ($payment->rent_amount ?? $payment->amount);
                $months[$month]['utility'] += (float) ($payment->utility_amount ?? 0.0);
            }
        }

        return $months;
    }

    /**
     * Like bookedLeaseMonthlyBreakdown(), but for the Steuerübersicht specifically:
     * German tax law applies the Zuflussprinzip (§ 11 EStG) to Mieteinnahmen – a
     * payment counts toward the tax year it was actually BOOKED (received), not the
     * year its rent period nominally falls into. A tenant who pays January's rent
     * already in the preceding December (an advance payment, see
     * SeedsBuchungen::rentPaymentPeriodForDate()) must show up in December's tax
     * year, not January's – the opposite of bookedLeaseMonthlyBreakdown()'s
     * period-based grouping, which the regular (non-tax) "Gebuchte Mieteinnahmen"
     * overview deliberately keeps, so it stays month-for-month comparable with
     * "Prognostizierte Mieteinnahmen" above it.
     *
     * @return array<int, array{rent: float, utility: float}>
     */
    private function taxRelevantLeaseMonthlyBreakdown(Lease $lease, int $year): array
    {
        $months = array_fill(1, 12, ['rent' => 0.0, 'utility' => 0.0]);

        foreach ($lease->leaseIntervals as $interval) {
            foreach ($interval->rentPayments as $payment) {
                if ($payment->booking_date === null || $payment->booking_date->year !== $year) {
                    continue;
                }

                $month = $payment->booking_date->month;
                $months[$month]['rent'] += (float) ($payment->rent_amount ?? $payment->amount);
                $months[$month]['utility'] += (float) ($payment->utility_amount ?? 0.0);
            }
        }

        return $months;
    }

    /**
     * @return array<int, float>|null null if the lease is not active at all during the year
     */
    private function activeLeaseMonthlyAmounts(Lease $lease, int $year): ?array
    {
        $breakdown = $this->activeLeaseMonthlyBreakdown($lease, $year);

        if ($breakdown === null) {
            return null;
        }

        return array_map(fn (array $m) => $m['rent'] + $m['utility'], $breakdown);
    }

    /**
     * @return array<int, array{rent: float, utility: float}>|null null if the lease is not active at all during the year
     */
    private function activeLeaseMonthlyBreakdown(Lease $lease, int $year): ?array
    {
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->endOfDay();

        if ($lease->start_date->gt($yearEnd) || ($lease->end_date !== null && $lease->end_date->lt($yearStart))) {
            return null;
        }

        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $monthStart = Carbon::create($year, $month, 1)->startOfDay();
            $monthEnd = $monthStart->copy()->endOfMonth();

            $interval = $lease->leaseIntervals->first(
                fn ($li) => $li->start_date->lte($monthEnd) && ($li->end_date === null || $li->end_date->gte($monthStart))
            );

            $months[$month] = [
                'rent' => (float) ($interval->rent_amount ?? 0.0),
                'utility' => (float) ($interval->utility_advance_payment ?? 0.0),
            ];
        }

        return $months;
    }

    /**
     * Group costs by category x month for the given year. Each category also breaks its
     * monthly amounts down by Rechnungssteller + Kundenaccount, each with its own month x
     * amount row, so the Oberfläche can show that breakdown on demand when a category row
     * is expanded (e.g. several loans all booked under "Zinsen"). Grouped by
     * Rechnungssteller + Account rather than by individual Invoice/Vertrag: two separate
     * Rechnungen billed by the same Rechnungssteller under the same Kundenaccount (e.g. a
     * Jahres-Einmalrechnung alongside the regular monatliche Rechnung for the same Konto)
     * belong in one combined row, not one row each.
     *
     * @param  Collection<int, Cost>  $costs
     * @return array{categories: array<int, array{name: string, months: array<int, float>, total: float, invoices: array<int, array{label: string, months: array<int, float>, total: float}>}>, totals: array<int, float>, grandTotal: float}
     */
    public function expensesByCategory(Collection $costs, int $year, string $dateField = 'period_date'): array
    {
        $categories = [];
        $totals = array_fill(1, 12, 0.0);

        foreach ($costs as $cost) {
            // "Prognostizierte Ausgaben" bucket by period_date: a regular cadence, one
            // value per period, per Wiederholung – unaffected by which exact calendar
            // day a booking actually happened on. "Gebuchte Ausgaben" bucket by
            // booking_date instead: the real month the money actually moved, so e.g. two
            // real bookings landing in the same calendar month (a bank booking an
            // installment a few days early/late) correctly sum into that one month,
            // leaving the neighboring month at 0 rather than showing the regular-cadence
            // placeholder amount there.
            $date = ($dateField === 'booking_date' ? $cost->booking_date : $cost->period_date)
                ?? $cost->period_date ?? $cost->billingInterval->start_date;

            if ($date->year !== $year) {
                continue;
            }

            $categoryId = $cost->cost_category_id;
            $month = $date->month;

            if (! isset($categories[$categoryId])) {
                $categories[$categoryId] = [
                    'name' => $cost->costCategory->name,
                    'months' => array_fill(1, 12, 0.0),
                    'total' => 0.0,
                    'invoices' => [],
                ];
            }

            $categories[$categoryId]['months'][$month] += (float) $cost->amount;
            $categories[$categoryId]['total'] += (float) $cost->amount;
            $totals[$month] += (float) $cost->amount;

            $groupKey = $cost->invoice === null
                ? 'none'
                : $cost->invoice->invoice_issuer_id.':'.($cost->invoice->invoice_issuer_account_id ?? 0);

            if (! isset($categories[$categoryId]['invoices'][$groupKey])) {
                $categories[$categoryId]['invoices'][$groupKey] = [
                    'label' => $this->issuerAccountLabel($cost->invoice),
                    'months' => array_fill(1, 12, 0.0),
                    'total' => 0.0,
                ];
            }

            $categories[$categoryId]['invoices'][$groupKey]['months'][$month] += (float) $cost->amount;
            $categories[$categoryId]['invoices'][$groupKey]['total'] += (float) $cost->amount;
        }

        foreach ($categories as &$category) {
            $invoices = array_values($category['invoices']);
            usort($invoices, fn ($a, $b) => $a['label'] <=> $b['label']);
            $category['invoices'] = $invoices;
        }
        unset($category);

        // Sorted by name rather than left in insertion order: "Prognostiziert" and
        // "Gebucht" are built from different (filtered) Cost subsets, so relying on
        // insertion order would list categories in different orders between the two
        // tables depending on which category's cost happened to appear first in each.
        usort($categories, fn ($a, $b) => $a['name'] <=> $b['name']);

        return [
            'categories' => $categories,
            'totals' => $totals,
            'grandTotal' => array_sum($totals),
        ];
    }

    /**
     * "Übersicht aller Buchungen" (Konzept.md "### Betriebskostenabrechnung"): every
     * Cost row for the given Abrechnungsintervall whose Kategorie is abrechnungsrelevant
     * (is_relevant_for_billing – a Kredit-Rate wie Zinsen/Tilgung z. B. nicht), grouped
     * by Kostenkategorie, regardless of ob die Zeile selbst schon gebucht ist. Dieselbe
     * Abrechnungsrelevanz-Einschränkung wie die eigentliche Betriebskostenabrechnung
     * selbst nutzt (BillingStatementController::render()'s $costsByCategory/
     * $costsByMonth) – dies ist die rohe Prüfliste dazu, nicht die Verteilung.
     *
     * @param  Collection<int, Cost>  $costs
     * @return array{categories: array<int, array{name: string, total: float, rows: array<int, array{date: string, label: string, amount: float, open: bool}>}>, grandTotal: float}
     */
    public function bookingsByCategory(Collection $costs, int $billingIntervalId): array
    {
        $categories = $costs
            ->where('billing_interval_id', $billingIntervalId)
            ->filter(fn (Cost $cost) => $cost->costCategory->is_relevant_for_billing)
            ->groupBy(fn (Cost $cost) => $cost->costCategory->name)
            ->map(fn (Collection $categoryCosts, string $name) => [
                'name' => $name,
                'total' => (float) $categoryCosts->sum('amount'),
                'rows' => $categoryCosts->sortBy('period_date')->values()->map(fn (Cost $cost) => [
                    'date' => $cost->period_date->toDateString(),
                    'label' => $this->issuerAccountLabel($cost->invoice),
                    // Nutzerfeedback: die Beschreibung der Rechnung mit anzeigen, damit
                    // z. B. zwei Buchungen desselben Rechnungsstellers/Kontos (oder ein
                    // falsch zugeordnetes Konto) trotzdem unterscheidbar bleiben.
                    'description' => $cost->invoice?->description,
                    'amount' => (float) $cost->amount,
                    'open' => $cost->isOpen(),
                ])->all(),
            ])
            ->sortBy('name')
            ->values();

        return [
            'categories' => $categories->all(),
            'grandTotal' => (float) $categories->sum('total'),
        ];
    }

    /**
     * Display label for a Cost's Rechnungssteller + Kundenaccount when a category's
     * monthly breakdown is expanded: "Rechnungssteller – Account", falling back to just
     * the Rechnungssteller when the Invoice has no Account, or a generic fallback when it
     * has no Rechnungssteller at all. A Cost can end up without an invoice at all if that
     * Invoice was since deleted (invoice_id is nulled, not cascaded, to keep the Cost as a
     * historical record).
     */
    private function issuerAccountLabel(?Invoice $invoice): string
    {
        if ($invoice === null) {
            return __('Ohne Rechnung');
        }

        $issuerName = $invoice->invoiceIssuer?->fullName();
        $accountLabel = $invoice->invoiceIssuerAccount?->description;

        if ($issuerName === null || $issuerName === '') {
            return __('Rechnung').' #'.$invoice->id;
        }

        return $accountLabel ? "{$issuerName} – {$accountLabel}" : $issuerName;
    }

    /**
     * Builds the Kontoübersicht: for every Konto of the group, the opening balance for
     * the given year (initial_balance plus all Cost-account allocations and transfers
     * before that year), the net monthly change, and the resulting closing balance.
     *
     * @return array{
     *     rows: array<int, array{account: Account, opening: float, months: array<int, float>, closing: float}>,
     *     openingTotal: float,
     *     monthTotals: array<int, float>,
     *     closingTotal: float
     * }
     */
    public function accountBalances(ManagementGroup $managementGroup, int $year): array
    {
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();

        $rows = [];
        $monthTotals = array_fill(1, 12, 0.0);
        $openingTotal = 0.0;
        $closingTotal = 0.0;

        foreach ($managementGroup->accounts as $account) {
            $opening = (float) $account->initial_balance + $this->accountNetChange($account, null, $yearStart);

            $months = array_fill(1, 12, 0.0);

            for ($month = 1; $month <= 12; $month++) {
                $monthStart = Carbon::create($year, $month, 1)->startOfDay();
                $monthEnd = $monthStart->copy()->addMonthNoOverflow();
                $months[$month] = $this->accountNetChange($account, $monthStart, $monthEnd);
            }

            $closing = $opening + array_sum($months);

            foreach ($months as $month => $amount) {
                $monthTotals[$month] += $amount;
            }
            $openingTotal += $opening;
            $closingTotal += $closing;

            $rows[] = [
                'account' => $account,
                'opening' => $opening,
                'months' => $months,
                'closing' => $closing,
            ];
        }

        return [
            'rows' => $rows,
            'openingTotal' => $openingTotal,
            'monthTotals' => $monthTotals,
            'closingTotal' => $closingTotal,
        ];
    }

    /**
     * Net balance change for an account within [start, end) — Cost allocations debit the
     * account (Ausgaben), RentPayment allocations credit it (Einnahmen), transfers
     * credit/debit depending on direction. A null $start means "from the beginning of time".
     */
    private function accountNetChange(Account $account, ?Carbon $start, Carbon $end): float
    {
        $costDebits = (float) DB::table('account_cost')
            ->join('costs', 'costs.id', '=', 'account_cost.cost_id')
            ->where('account_cost.account_id', $account->id)
            ->when($start, fn ($q) => $q->where('costs.period_date', '>=', $start))
            ->where('costs.period_date', '<', $end)
            ->sum('account_cost.amount');

        $rentCredits = (float) DB::table('account_rent_payment')
            ->join('rent_payments', 'rent_payments.id', '=', 'account_rent_payment.rent_payment_id')
            ->where('account_rent_payment.account_id', $account->id)
            ->when($start, fn ($q) => $q->where('rent_payments.period_date', '>=', $start))
            ->where('rent_payments.period_date', '<', $end)
            ->sum('account_rent_payment.amount');

        $transfersOut = (float) DB::table('account_transfers')
            ->where('from_account_id', $account->id)
            ->when($start, fn ($q) => $q->where('transfer_date', '>=', $start))
            ->where('transfer_date', '<', $end)
            ->sum('amount');

        $transfersIn = (float) DB::table('account_transfers')
            ->where('to_account_id', $account->id)
            ->when($start, fn ($q) => $q->where('transfer_date', '>=', $start))
            ->where('transfer_date', '<', $end)
            ->sum('amount');

        return -$costDebits + $rentCredits - $transfersOut + $transfersIn;
    }
}
