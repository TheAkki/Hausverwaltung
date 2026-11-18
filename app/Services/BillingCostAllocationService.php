<?php

namespace App\Services;

use App\Models\BillingInterval;
use App\Models\Cost;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\RentalUnit;
use App\Models\SettlementClaim;
use Closure;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Implements the Betriebskostenabrechnung's mehrstufige Berechnung (Konzept.md
 * "#### Berechnung"): a Mietvertrag's Betriebskosten-Anteil is the sum of four
 * Stufen – costs booked directly at Verwaltungsgruppen-, Verwaltungseinheiten-,
 * Mieteinheiten- and Mietvertrags-Ebene – each apportioned to this lease according
 * to its CostCategory's Abrechnungsart (allocation_key):
 *
 *   |                | Verwaltungsgruppe        | Verwaltungseinheit       | Mieteinheit | Mietvertrag |
 *   | individual     | ignoriert                | ignoriert                | ignoriert   | 1           |
 *   | person_count   | PM(Mieter) / PM(VG)      | PM(Mieter) / PM(VE)      | 1           | ignoriert   |
 *   | area           | Fläche(Mieter) / Fläche(VG) | Fläche(Mieter) / Fläche(VE) | 1       | ignoriert   |
 *   | rental_unit    | 1 / Anzahl relevante ME(VG) | 1 / Anzahl relevante ME(VE) | 1     | ignoriert   |
 *
 * "Individuell" gebuchte Kosten zählen nur auf Mietvertragsebene (siehe
 * CostCategoryAllowsLeaseCostable, das dies bereits beim Anlegen erzwingt); auf den
 * anderen drei Ebenen werden sie ignoriert, statt fälschlich mit Faktor 0 oder 1
 * eingerechnet zu werden.
 */
class BillingCostAllocationService
{
    public function __construct(private readonly FinancialOverviewService $overview) {}

    /**
     * @return array{
     *     byCategory: Collection<string, float>,
     *     byStage: array{
     *         managementGroup: array{byCategory: Collection<string, float>, categoryDetails: Collection<string, array{sum: float, key: string, share: float}>, total: float},
     *         managementUnit: array{byCategory: Collection<string, float>, categoryDetails: Collection<string, array{sum: float, key: string, share: float}>, total: float},
     *         rentalUnit: array{byCategory: Collection<string, float>, categoryDetails: Collection<string, array{sum: float, key: string, share: float}>, total: float},
     *         lease: array{byCategory: Collection<string, float>, categoryDetails: Collection<string, array{sum: float, key: string, share: float}>, total: float}
     *     },
     *     total: float
     * }
     */
    public function allocateForLease(
        ManagementGroup $managementGroup,
        ManagementUnit $managementUnit,
        RentalUnit $rentalUnit,
        Lease $lease,
        BillingInterval $billingInterval,
        Carbon $leasePeriodStart,
        Carbon $leasePeriodEnd,
        ?array $precomputed = null,
    ): array {
        // These six aggregates (plus the Kategorie→Abrechnungsschlüssel map) don't vary
        // per Mietvertrag within one Betriebskostenabrechnung – a caller generating a
        // statement for several Mietverträge at once (BillingStatementController::render())
        // computes them ONCE via precomputeForStatement() and passes the result here for
        // every lease, instead of this method redoing the same group-/unit-wide queries
        // and month-by-month Personenmonate scan on every single call.
        $precomputed ??= $this->precomputeForStatement($managementGroup, $managementUnit, $billingInterval);
        [
            'allocationKeys' => $allocationKeys,
            'personMonthsGroup' => $personMonthsGroup,
            'personMonthsUnit' => $personMonthsUnit,
            'areaGroup' => $areaGroup,
            'areaUnit' => $areaUnit,
            'relevantUnitsGroup' => $relevantUnitsGroup,
            'relevantUnitsUnit' => $relevantUnitsUnit,
        ] = $precomputed;

        $personMonthsMieter = $this->overview->personMonthsForLease($lease, $leasePeriodStart, $leasePeriodEnd);
        $areaMieter = (float) $rentalUnit->size;

        // Zeitfaktor: Tage(Mietdauer im Abrechnungsintervall) / Tage(Abrechnungsintervall).
        // Jede Stufe wird zusätzlich damit skaliert, sodass ein Mieter, der nur einen Teil
        // des Intervalls gemietet hat, auch nur den entsprechenden Zeitanteil der Kosten
        // trägt (der Rest – z. B. Leerstand – verbleibt beim Vermieter).
        $intervalDays = $billingInterval->start_date->diffInDays($billingInterval->end_date) + 1;
        $leaseDays = $leasePeriodStart->diffInDays($leasePeriodEnd) + 1;
        $timeFactor = $intervalDays > 0 ? $leaseDays / $intervalDays : 0.0;

        $groupStage = $this->allocateLevelCosts(
            $managementGroup->costs()->where('billing_interval_id', $billingInterval->id),
            $allocationKeys,
            fn (string $key) => match ($key) {
                'person_count' => $personMonthsGroup > 0 ? $personMonthsMieter / $personMonthsGroup : 0.0,
                'area' => $areaGroup > 0 ? $areaMieter / $areaGroup : 0.0,
                'rental_unit' => $relevantUnitsGroup > 0 ? 1 / $relevantUnitsGroup : 0.0,
                default => null,
            },
            $timeFactor
        );

        $unitStage = $this->allocateLevelCosts(
            $managementUnit->costs()->where('billing_interval_id', $billingInterval->id),
            $allocationKeys,
            fn (string $key) => match ($key) {
                'person_count' => $personMonthsUnit > 0 ? $personMonthsMieter / $personMonthsUnit : 0.0,
                'area' => $areaUnit > 0 ? $areaMieter / $areaUnit : 0.0,
                'rental_unit' => $relevantUnitsUnit > 0 ? 1 / $relevantUnitsUnit : 0.0,
                default => null,
            },
            $timeFactor
        );

        $rentalUnitStage = $this->allocateLevelCosts(
            $rentalUnit->costs()->where('billing_interval_id', $billingInterval->id),
            $allocationKeys,
            fn (string $key) => in_array($key, ['person_count', 'area', 'rental_unit'], true) ? 1.0 : null,
            $timeFactor
        );

        // Konzept.md "#### Berechnung": "Nur die Kosten mit individueller Abrechnung auf
        // einen Mieter werden nicht mit der Mietdauer skaliert. Diese werden 1:1
        // übernommen." – anders als die drei anderen Stufen deshalb OHNE $timeFactor:
        // eine individuell zugewiesene Kostenzeile (z. B. ein von diesem Mieter
        // verursachter Schaden) gehört ihm in voller Höhe, unabhängig davon, wie groß
        // sein Zeitanteil am Abrechnungsintervall ist.
        $leaseStage = $this->allocateLevelCosts(
            $lease->costs()->where('billing_interval_id', $billingInterval->id),
            $allocationKeys,
            fn (string $key) => $key === 'individual' ? 1.0 : null,
        );

        $byCategory = collect();

        foreach ([$groupStage, $unitStage, $rentalUnitStage, $leaseStage] as $stage) {
            foreach ($stage['byCategory'] as $categoryName => $amount) {
                $byCategory[$categoryName] = ($byCategory[$categoryName] ?? 0.0) + $amount;
            }
        }

        return [
            'byCategory' => $byCategory,
            // Each stage keeps its own per-category breakdown (['byCategory']) next to
            // its ['total'], so a template can show e.g. "Heizkosten 240 € auf Ebene
            // der Verwaltungsgruppe" and not just the stage's lump sum.
            'byStage' => [
                'managementGroup' => $groupStage,
                'managementUnit' => $unitStage,
                'rentalUnit' => $rentalUnitStage,
                'lease' => $leaseStage,
            ],
            'total' => $groupStage['total'] + $unitStage['total'] + $rentalUnitStage['total'] + $leaseStage['total'],
        ];
    }

    /**
     * Precomputes everything allocateForLease() needs that stays the same for every
     * Mietvertrag of one Betriebskostenabrechnung: the Kategorie→Abrechnungsschlüssel
     * map and the Verwaltungsgruppen-/Verwaltungseinheiten-level Personenmonate/Fläche/
     * Anzahl relevanter Mieteinheiten. Call this once per statement and pass the result
     * to every allocateForLease() call for it (see the $precomputed parameter there).
     *
     * @return array{
     *     allocationKeys: array<int, string>,
     *     personMonthsGroup: int, personMonthsUnit: int,
     *     areaGroup: float, areaUnit: float,
     *     relevantUnitsGroup: int, relevantUnitsUnit: int,
     * }
     */
    public function precomputeForStatement(ManagementGroup $managementGroup, ManagementUnit $managementUnit, BillingInterval $billingInterval): array
    {
        $groupRentalUnits = $managementGroup->managementUnits->flatMap->rentalUnits;
        $unitRentalUnits = $managementUnit->rentalUnits;

        return [
            'allocationKeys' => $this->allocationKeysByCategoryId($managementGroup),
            'personMonthsGroup' => $this->overview->personMonths($groupRentalUnits, $billingInterval->start_date, $billingInterval->end_date),
            'personMonthsUnit' => $this->overview->personMonths($unitRentalUnits, $billingInterval->start_date, $billingInterval->end_date),
            'areaGroup' => (float) $groupRentalUnits->sum('size'),
            'areaUnit' => (float) $unitRentalUnits->sum('size'),
            // Abrechnungsschlüssel "Mieteinheit" (rental_unit): die Kosten werden zu
            // gleichen Teilen auf die abrechnungsrelevanten Mieteinheiten der
            // jeweiligen Verwaltungsebene verteilt.
            'relevantUnitsGroup' => $groupRentalUnits->where('is_relevant_for_billing', true)->count(),
            'relevantUnitsUnit' => $unitRentalUnits->where('is_relevant_for_billing', true)->count(),
        ];
    }

    /**
     * Suggests a new monthly Betriebskostenvorauszahlung for a Mietvertrag, based on
     * this Betriebskostenabrechnung's actual result: the Mietvertrag's Betriebskosten
     * total ($total, i.e. allocateForLease()'s ['total']) divided by the number of
     * months in the Abrechnungsintervall gives a monthly cost, divided by the
     * Mieteinheit's Fläche gives a monthly cost per m² – rounded UP to the next full
     * 10 Cent (a clean, easy-to-communicate rate, Konzept.md "##### Berechnung neue
     * Vorauszahlung" Regel a), then multiplied back by the Fläche to arrive at the raw
     * suggested monthly Vorauszahlung in Euro.
     *
     * Diese rohe Zahl wird dann noch gegen die bisherige Vorauszahlung ($currentAdvance)
     * geprüft (Regeln b–d):
     * b) Läuft der Mietvertrag noch keine 3 Monate ($leaseDurationMonths, gemessen vom
     *    Beginn des Mietvertrags bis zum Ende des Abrechnungsintervalls), ist die
     *    Datenbasis zu dünn – die bisherige Vorauszahlung bleibt unverändert.
     * c) Eine Reduzierung erfolgt nur, wenn die neue Vorauszahlung unter 90 % der
     *    bisherigen liegt.
     * d) Eine Erhöhung erfolgt nur, wenn die neue Vorauszahlung mehr als 5 % über der
     *    bisherigen liegt.
     * Innerhalb dieser Toleranzbandbreite (90–105 % der bisherigen Vorauszahlung)
     * bleibt die bisherige Vorauszahlung unverändert, um triviale Anpassungen wegen
     * geringer Schwankungen zu vermeiden.
     */
    public function suggestedMonthlyUtilityAdvance(float $total, float $area, int $intervalMonths, float $currentAdvance = 0.0, int $leaseDurationMonths = PHP_INT_MAX): float
    {
        if ($area <= 0.0 || $intervalMonths <= 0) {
            return 0.0;
        }

        $monthlyCostPerArea = ($total / $intervalMonths) / $area;

        // Round to 6 decimals first to absorb floating-point noise (e.g. 2.5000000001),
        // so an already-clean per-m² rate isn't bumped up to the next 10-Cent step.
        $roundedCostPerArea = ceil(round($monthlyCostPerArea * 10, 6)) / 10;

        $suggested = round($roundedCostPerArea * $area, 2);

        if ($leaseDurationMonths < 3) {
            return $currentAdvance;
        }

        if ($suggested < $currentAdvance * 0.9 || $suggested > $currentAdvance * 1.05) {
            return $suggested;
        }

        return $currentAdvance;
    }

    /**
     * @return array<int, string> cost_category_id => allocation_key (defaults to the
     *                            "area" DB column default for a category without an
     *                            explicit management_group_cost_category row)
     */
    private function allocationKeysByCategoryId(ManagementGroup $managementGroup): array
    {
        return $managementGroup->costCategories()->get()
            ->mapWithKeys(fn ($category) => [$category->id => $category->pivot->allocation_key])
            ->all();
    }

    /**
     * Sums one Ebene's abrechnungsrelevante Kosten (for this BillingInterval) by
     * category, and multiplies each category's sum by the allocation factor
     * $factorFor() returns for that category's Abrechnungsart – a null factor means
     * "ignore this category at this Ebene" (e.g. "individual" above Mietvertrag-Ebene).
     *
     * @param  MorphMany<Cost, *>  $query
     * @param  array<int, string>  $allocationKeys
     * @param  Closure(string): ?float  $factorFor
     * @param  float  $timeFactor  additionally scales every share (Mietdauer / Intervalldauer)
     * @return array{byCategory: Collection<string, float>, categoryDetails: Collection<string, array{sum: float, key: string, share: float}>, total: float}
     */
    private function allocateLevelCosts($query, array $allocationKeys, Closure $factorFor, float $timeFactor = 1.0): array
    {
        $costs = $query->with('costCategory')->get()->filter(fn ($cost) => $cost->costCategory->is_relevant_for_billing);

        $byCategory = collect();
        // Ergänzt byCategory (nur der bereits anteilig berechnete Betrag) um die
        // Rohsumme dieser Ebene und den Namen des Abrechnungsschlüssels je Kategorie –
        // für eine Vorlage, die (wie die reale Vorjahres-Vorlage) "Summe/Schlüssel/
        // Anteil" nebeneinander zeigen will. byCategory bleibt unverändert (Kompatibilität
        // zu bereits bestehenden Templates, die dort nur den Betrag erwarten).
        $categoryDetails = collect();
        $total = 0.0;

        foreach ($costs->groupBy('cost_category_id') as $categoryId => $categoryCosts) {
            $allocationKey = $allocationKeys[$categoryId] ?? 'area';
            $factor = $factorFor($allocationKey);

            if ($factor === null) {
                continue;
            }

            $sum = $categoryCosts->sum('amount');
            $share = $sum * $factor * $timeFactor;
            $categoryName = $categoryCosts->first()->costCategory->name;
            $byCategory[$categoryName] = ($byCategory[$categoryName] ?? 0.0) + $share;
            $categoryDetails[$categoryName] = [
                'sum' => ($categoryDetails[$categoryName]['sum'] ?? 0.0) + $sum,
                'key' => $this->allocationKeyLabel($allocationKey),
                'share' => $byCategory[$categoryName],
            ];
            $total += $share;
        }

        return ['byCategory' => $byCategory, 'categoryDetails' => $categoryDetails, 'total' => $total];
    }

    /**
     * German display label for an allocation_key, matching the wording already used
     * on the Verwaltungsgruppen-Seite's Abrechnungsschlüssel-Dropdown.
     */
    private function allocationKeyLabel(string $allocationKey): string
    {
        return match ($allocationKey) {
            'person_count' => 'Personen',
            'individual' => 'Individuell',
            'rental_unit' => 'Mieteinheit',
            default => 'Fläche',
        };
    }

    /**
     * Konzept.md "##### Leerstände": "Abrechnungsrelevante, aber leerstehende
     * Mieteinheiten müssen berücksichtigt werden. Berechnet werden diese mit einem 0
     * Personenhaushalt. Ansonsten werden sie wie ein Mieter gehandhabt." – für jede
     * Leerstands-Lücke (siehe vacancyGaps()) einer abrechnungsrelevanten Mieteinheit
     * dieser Verwaltungseinheit wird ein "Phantom-Mietvertrag" gebaut, im selben
     * Eintrags-Format wie BillingStatementLeaseResolver::forInterval() (Konzept.md:
     * "Aufführung in der Betriebskostenabrechnung"), aber nie persistiert.
     *
     * Läuft dadurch unverändert durch dieselbe allocateForLease()-Pipeline wie ein
     * echter Mietvertrag: eine nicht gespeicherte Lease liefert für jede darauf
     * angewiesene Relation (leaseIntervals, tenants, costs) automatisch eine leere
     * Collection/Query (ihr Fremdschlüssel-Wert ist null – Eloquents Query Builder
     * baut aus "where(x, '=', null)" automatisch ein "whereNull(x)", das nie trifft),
     * wodurch Personenmonate = 0 und individuelle (Stufe-4-)Kosten = 0 ganz von selbst
     * herauskommen, ohne jeden Sonderfall in allocateForLease() selbst. Fläche- und
     * Mieteinheit-Schlüssel bleiben dagegen reell (die Mieteinheit existiert ja
     * wirklich, nur ohne Mieter), sodass ihr Vermieteranteil für diese beiden
     * Schlüssel weiterhin korrekt anfällt.
     *
     * @return Collection<int, array{lease: Lease, tenants: Collection, tenantNames: string,
     *     rentalUnit: RentalUnit, periodStart: Carbon, periodEnd: Carbon, days: int,
     *     currentColdRent: float, currentUtilityAdvance: float,
     *     monthlyUtilityAdvances: Collection, totalUtilityAdvance: float,
     *     otherRents: Collection, isVacancy: true, claims: Collection<int, SettlementClaim>}>
     */
    public function vacancyLeaseEntries(ManagementUnit $managementUnit, BillingInterval $billingInterval): Collection
    {
        $intervalStart = $billingInterval->start_date->copy()->startOfDay();
        $intervalEnd = $billingInterval->end_date->copy()->startOfDay();

        return $managementUnit->rentalUnits
            ->where('is_relevant_for_billing', true)
            ->flatMap(function (RentalUnit $rentalUnit) use ($intervalStart, $intervalEnd, $billingInterval) {
                // Alle Forderungen dieser Mieteinheit für dieses Abrechnungsintervall –
                // bei mehreren Leerstands-Lücken derselben Mieteinheit im selben
                // Intervall (seltener Fall: zwischenzeitlich kurz vermietet) bewusst
                // vereinfacht jeder ihrer Lücken-Zeilen gleichermaßen zugeordnet, statt
                // zu versuchen, eine Forderung einer bestimmten Lücke zuzurechnen.
                $claims = $rentalUnit->settlementClaims()
                    ->where('billing_interval_id', $billingInterval->id)
                    ->orderByDesc('date')
                    ->get();

                return collect($this->vacancyGaps($rentalUnit, $intervalStart, $intervalEnd))
                    ->map(fn (array $gap) => [
                        'lease' => new Lease(['rental_unit_id' => $rentalUnit->id, 'start_date' => $gap['start'], 'end_date' => $gap['end']]),
                        'tenants' => collect(),
                        'tenantNames' => 'Leerstand',
                        'rentalUnit' => $rentalUnit,
                        'periodStart' => $gap['start'],
                        'periodEnd' => $gap['end'],
                        'days' => $gap['days'],
                        'currentColdRent' => 0.0,
                        'currentUtilityAdvance' => 0.0,
                        'monthlyUtilityAdvances' => collect(),
                        'totalUtilityAdvance' => 0.0,
                        'otherRents' => collect(),
                        'isVacancy' => true,
                        'claims' => $claims,
                    ]);
            })->values();
    }

    /**
     * The date ranges within [$intervalStart, $intervalEnd] that none of this
     * Mieteinheit's Mietverträge (regardless of Vorvertrag/gekoppelt – jeder Mietvertrag
     * dieser Mieteinheit steht für tatsächliche Belegung) covers. A Mietvertrag without
     * an Enddatum is treated as covering through the end of the queried window.
     *
     * @return array<int, array{start: Carbon, end: Carbon, days: int}>
     */
    private function vacancyGaps(RentalUnit $rentalUnit, Carbon $intervalStart, Carbon $intervalEnd): array
    {
        $occupied = $rentalUnit->leases
            ->map(fn (Lease $lease) => [
                'start' => $lease->start_date->copy()->startOfDay(),
                'end' => $lease->end_date?->copy()->startOfDay() ?? $intervalEnd->copy(),
            ])
            ->filter(fn (array $period) => ! $period['end']->lt($intervalStart) && ! $period['start']->gt($intervalEnd))
            ->sortBy('start')
            ->values();

        $gaps = [];
        $cursor = $intervalStart->copy();

        foreach ($occupied as $period) {
            if ($period['start']->gt($cursor)) {
                $gaps[] = $this->vacancyGap($cursor, $period['start']->copy()->subDay());
            }

            if ($period['end']->gte($cursor)) {
                $cursor = $period['end']->copy()->addDay();
            }
        }

        if ($cursor->lte($intervalEnd)) {
            $gaps[] = $this->vacancyGap($cursor, $intervalEnd);
        }

        return $gaps;
    }

    /**
     * @return array{start: Carbon, end: Carbon, days: int}
     */
    private function vacancyGap(Carbon $start, Carbon $end): array
    {
        return ['start' => $start, 'end' => $end, 'days' => (int) $start->diffInDays($end) + 1];
    }
}
