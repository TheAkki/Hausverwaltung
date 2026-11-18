<?php

namespace Tests\Unit;

use App\Models\BillingInterval;
use App\Models\CostCategory;
use App\Models\Group;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\RentalUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingCostAllocationService;
use App\Services\FinancialOverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Covers the "#### Berechnung" Stufen 1-4 (Konzept.md): a Mietvertrag's Betriebskosten-
 * Anteil is the sum of costs booked at Verwaltungsgruppen-, Verwaltungseinheiten-,
 * Mieteinheiten- and Mietvertrags-Ebene, each apportioned according to the cost
 * category's allocation_key (person_count/area: ratio of the Mieter's own PM/Fläche to
 * the level's total; individual: only counted at Mietvertrags-Ebene itself).
 */
class BillingCostAllocationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): BillingCostAllocationService
    {
        return new BillingCostAllocationService(new FinancialOverviewService);
    }

    private function makeLease(RentalUnit $rentalUnit, string $tenantName, int $occupantCount): Lease
    {
        $tenant = Tenant::create([
            'first_name' => $tenantName, 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ]);
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);
        $lease->currentLeaseInterval()->update(['occupant_count' => $occupantCount]);

        return $lease;
    }

    /**
     * Baut die volle Testfixtur:
     * - ManagementGroup MG mit zwei Verwaltungseinheiten A und B.
     * - Einheit A: R1 (60m², unser zu prüfender Mietvertrag L1, 2 Personen) + R3 (40m², 1 Person).
     * - Einheit B: R2 (100m², 3 Personen) – nur zur Gesamtsumme der Verwaltungsgruppe.
     * - Flächen: Fläche(Mieter)=60, Fläche(VE)=100 (R1+R3), Fläche(VG)=200 (R1+R3+R2).
     * - Personenmonate (12 Monate Jan-Dez 2026): PM(Mieter)=24, PM(VE)=36 (24+12), PM(VG)=72 (24+12+36).
     *
     * @return array{0: ManagementGroup, 1: ManagementUnit, 2: RentalUnit, 3: Lease, 4: Carbon, 5: Carbon}
     */
    private function makeFixture(): array
    {
        $user = User::factory()->create();
        $group = Group::create(['name' => 'Berechnung Gruppe', 'owner_id' => $user->id]);
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);

        $unitA = $managementGroup->managementUnits()->create(['street' => 'A-Straße', 'postal_code' => '1', 'city' => 'X']);
        $unitB = $managementGroup->managementUnits()->create(['street' => 'B-Straße', 'postal_code' => '1', 'city' => 'X']);

        $r1 = $unitA->rentalUnits()->create(['type' => 'Wohnung', 'size' => 60, 'is_relevant_for_billing' => true]);
        $r3 = $unitA->rentalUnits()->create(['type' => 'Wohnung', 'size' => 40, 'is_relevant_for_billing' => true]);
        $r2 = $unitB->rentalUnits()->create(['type' => 'Wohnung', 'size' => 100, 'is_relevant_for_billing' => true]);

        $l1 = $this->makeLease($r1, 'Max', 2);
        $this->makeLease($r3, 'Erika', 1);
        $this->makeLease($r2, 'Otto', 3);

        $billingInterval = $managementGroup->billingIntervals()->first();
        $billingInterval->update(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        return [$managementGroup->fresh(), $unitA->fresh(), $r1->fresh(), $l1->fresh(), Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31')];
    }

    private function attachCategory(ManagementGroup $managementGroup, string $name, string $allocationKey): CostCategory
    {
        $category = CostCategory::create(['name' => $name, 'is_relevant_for_billing' => true]);
        $managementGroup->costCategories()->attach($category->id, ['allocation_key' => $allocationKey]);

        return $category;
    }

    public function test_a_person_count_category_booked_at_management_group_level_is_split_by_the_leases_own_person_months_share(): void
    {
        [$managementGroup, $unitA, $r1, $l1, $start, $end] = $this->makeFixture();
        $billingInterval = $managementGroup->billingIntervals()->first();
        $category = $this->attachCategory($managementGroup, 'Personen-Kosten', 'person_count');

        $managementGroup->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'amount' => 900, 'period_date' => '2026-06-01',
        ]);

        $result = $this->service()->allocateForLease($managementGroup, $unitA, $r1, $l1, $billingInterval, $start, $end);

        // PM(Mieter)=24, PM(VG)=72 => Anteil 1/3 von 900 = 300.
        $this->assertEqualsWithDelta(300.0, $result['byStage']['managementGroup']['total'], 0.01);
        $this->assertEqualsWithDelta(300.0, $result['byCategory']['Personen-Kosten'], 0.01);
    }

    public function test_an_area_category_booked_at_management_group_level_is_split_by_the_leases_own_area_share(): void
    {
        [$managementGroup, $unitA, $r1, $l1, $start, $end] = $this->makeFixture();
        $billingInterval = $managementGroup->billingIntervals()->first();
        $category = $this->attachCategory($managementGroup, 'Flächen-Kosten-VG', 'area');

        $managementGroup->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'amount' => 1000, 'period_date' => '2026-06-01',
        ]);

        $result = $this->service()->allocateForLease($managementGroup, $unitA, $r1, $l1, $billingInterval, $start, $end);

        // Fläche(Mieter)=60, Fläche(VG)=200 (R1+R3+R2) => Anteil 0.3 von 1000 = 300.
        $this->assertEqualsWithDelta(300.0, $result['byStage']['managementGroup']['total'], 0.01);
    }

    public function test_a_person_count_category_booked_at_management_unit_level_is_split_by_the_leases_own_person_months_share(): void
    {
        [$managementGroup, $unitA, $r1, $l1, $start, $end] = $this->makeFixture();
        $billingInterval = $managementGroup->billingIntervals()->first();
        $category = $this->attachCategory($managementGroup, 'Personen-Kosten-VE', 'person_count');

        $unitA->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'amount' => 900, 'period_date' => '2026-06-01',
        ]);

        $result = $this->service()->allocateForLease($managementGroup, $unitA, $r1, $l1, $billingInterval, $start, $end);

        // PM(Mieter)=24, PM(VE)=36 (24+12) => Anteil 2/3 von 900 = 600.
        $this->assertEqualsWithDelta(600.0, $result['byStage']['managementUnit']['total'], 0.01);
    }

    public function test_an_area_category_booked_at_management_unit_level_is_split_by_the_leases_own_area_share(): void
    {
        [$managementGroup, $unitA, $r1, $l1, $start, $end] = $this->makeFixture();
        $billingInterval = $managementGroup->billingIntervals()->first();
        $category = $this->attachCategory($managementGroup, 'Flächen-Kosten', 'area');

        $unitA->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'amount' => 500, 'period_date' => '2026-06-01',
        ]);

        $result = $this->service()->allocateForLease($managementGroup, $unitA, $r1, $l1, $billingInterval, $start, $end);

        // Fläche(Mieter)=60, Fläche(VE)=100 (R1+R3) => Anteil 0.6 von 500 = 300.
        $this->assertEqualsWithDelta(300.0, $result['byStage']['managementUnit']['total'], 0.01);
    }

    public function test_a_cost_booked_directly_at_rental_unit_level_is_allocated_in_full_regardless_of_allocation_key(): void
    {
        [$managementGroup, $unitA, $r1, $l1, $start, $end] = $this->makeFixture();
        $billingInterval = $managementGroup->billingIntervals()->first();
        $category = $this->attachCategory($managementGroup, 'Mieteinheit-Kosten', 'person_count');

        $r1->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'amount' => 120, 'period_date' => '2026-06-01',
        ]);

        $result = $this->service()->allocateForLease($managementGroup, $unitA, $r1, $l1, $billingInterval, $start, $end);

        $this->assertEqualsWithDelta(120.0, $result['byStage']['rentalUnit']['total'], 0.01);
    }

    public function test_an_individual_cost_booked_directly_at_the_lease_is_allocated_in_full(): void
    {
        [$managementGroup, $unitA, $r1, $l1, $start, $end] = $this->makeFixture();
        $billingInterval = $managementGroup->billingIntervals()->first();
        $category = $this->attachCategory($managementGroup, 'Individuelle Kosten', 'individual');

        $l1->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'amount' => 50, 'period_date' => '2026-06-01',
        ]);

        $result = $this->service()->allocateForLease($managementGroup, $unitA, $r1, $l1, $billingInterval, $start, $end);

        $this->assertEqualsWithDelta(50.0, $result['byStage']['lease']['total'], 0.01);
    }

    /**
     * Konzept.md "#### Berechnung": "Nur die Kosten mit individueller Abrechnung auf
     * einen Mieter werden nicht mit der Mietdauer skaliert. Diese werden 1:1
     * übernommen." – anders als die anderen drei Stufen bleibt eine individuell
     * zugewiesene Kostenzeile auch dann in voller Höhe bei diesem Mietvertrag, wenn er
     * nur einen Teil des Abrechnungsintervalls gemietet hat.
     */
    public function test_an_individual_cost_is_not_scaled_by_the_leases_partial_period(): void
    {
        [$managementGroup, $unitA, $r1, $l1] = $this->makeFixture();
        $billingInterval = $managementGroup->billingIntervals()->first();
        $category = $this->attachCategory($managementGroup, 'Individuelle Kosten', 'individual');

        $l1->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'amount' => 50, 'period_date' => '2026-06-01',
        ]);

        // Nur die zweite Hälfte des Abrechnungsintervalls (Zeitfaktor 0,5) – bei den
        // anderen drei Stufen würde das den Anteil halbieren, bei individuellen Kosten
        // nicht.
        $result = $this->service()->allocateForLease(
            $managementGroup, $unitA, $r1, $l1, $billingInterval,
            Carbon::parse('2026-07-01'), Carbon::parse('2026-12-31')
        );

        $this->assertEqualsWithDelta(50.0, $result['byStage']['lease']['total'], 0.01);
    }

    public function test_an_individual_category_cost_booked_at_management_group_level_is_ignored(): void
    {
        [$managementGroup, $unitA, $r1, $l1, $start, $end] = $this->makeFixture();
        $billingInterval = $managementGroup->billingIntervals()->first();
        $category = $this->attachCategory($managementGroup, 'Individuelle Kosten', 'individual');

        // Kann über die UI/Validierung nicht entstehen (CostCategoryAllowsLeaseCostable
        // erlaubt "individual" nur auf Mietvertragsebene), aber die Berechnung selbst
        // muss so eine Buchung trotzdem ignorieren, statt sie fälschlich einzurechnen.
        $managementGroup->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'amount' => 1000, 'period_date' => '2026-06-01',
        ]);

        $result = $this->service()->allocateForLease($managementGroup, $unitA, $r1, $l1, $billingInterval, $start, $end);

        $this->assertSame(0.0, $result['byStage']['managementGroup']['total']);
        $this->assertSame(0.0, $result['total']);
    }

    public function test_all_four_stages_are_summed_into_the_total(): void
    {
        [$managementGroup, $unitA, $r1, $l1, $start, $end] = $this->makeFixture();
        $billingInterval = $managementGroup->billingIntervals()->first();

        $personCategory = $this->attachCategory($managementGroup, 'Personen-Kosten', 'person_count');
        $areaCategory = $this->attachCategory($managementGroup, 'Flächen-Kosten', 'area');
        $unitCategory = $this->attachCategory($managementGroup, 'Mieteinheit-Kosten', 'area');
        $individualCategory = $this->attachCategory($managementGroup, 'Individuelle Kosten', 'individual');

        $managementGroup->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $personCategory->id,
            'amount' => 900, 'period_date' => '2026-06-01',
        ]);
        $unitA->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $areaCategory->id,
            'amount' => 500, 'period_date' => '2026-06-01',
        ]);
        $r1->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $unitCategory->id,
            'amount' => 120, 'period_date' => '2026-06-01',
        ]);
        $l1->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $individualCategory->id,
            'amount' => 50, 'period_date' => '2026-06-01',
        ]);

        $result = $this->service()->allocateForLease($managementGroup, $unitA, $r1, $l1, $billingInterval, $start, $end);

        // 300 (VG) + 300 (VE) + 120 (Mieteinheit) + 50 (Mietvertrag) = 770.
        $this->assertEqualsWithDelta(770.0, $result['total'], 0.01);
    }

    public function test_each_stage_also_exposes_its_own_per_category_breakdown_alongside_its_total(): void
    {
        [$managementGroup, $unitA, $r1, $l1, $start, $end] = $this->makeFixture();
        $billingInterval = $managementGroup->billingIntervals()->first();

        $personCategory = $this->attachCategory($managementGroup, 'Personen-Kosten', 'person_count');
        $areaCategory = $this->attachCategory($managementGroup, 'Flächen-Kosten', 'area');

        // Zwei Kategorien auf Verwaltungsgruppen-Ebene, damit sich die Aufschlüsselung
        // je Kategorie von der Stufensumme unterscheidet.
        $managementGroup->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $personCategory->id,
            'amount' => 900, 'period_date' => '2026-06-01', // PM-Anteil 1/3 => 300
        ]);
        $managementGroup->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $areaCategory->id,
            'amount' => 1000, 'period_date' => '2026-06-01', // Flächen-Anteil 0.3 => 300
        ]);

        $result = $this->service()->allocateForLease($managementGroup, $unitA, $r1, $l1, $billingInterval, $start, $end);

        $groupStage = $result['byStage']['managementGroup'];
        $this->assertEqualsWithDelta(600.0, $groupStage['total'], 0.01);
        $this->assertEqualsWithDelta(300.0, $groupStage['byCategory']['Personen-Kosten'], 0.01);
        $this->assertEqualsWithDelta(300.0, $groupStage['byCategory']['Flächen-Kosten'], 0.01);
        // Andere Stufen ohne Buchungen haben eine leere Aufschlüsselung und Summe 0.
        $this->assertSame(0.0, $result['byStage']['lease']['total']);
        $this->assertCount(0, $result['byStage']['lease']['byCategory']);
    }

    public function test_costs_are_scaled_by_the_time_factor_when_the_lease_covers_only_part_of_the_interval(): void
    {
        [$managementGroup, $unitA, $r1, $l1] = $this->makeFixture();
        $billingInterval = $managementGroup->billingIntervals()->first();
        $category = $this->attachCategory($managementGroup, 'Mieteinheit-Kosten', 'area');

        // Direkt auf Mieteinheiten-Ebene gebucht (Faktor 1), damit sich nur der
        // Zeitfaktor auswirkt und nicht zusätzlich ein Flächen-/Personen-Anteil.
        $r1->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'amount' => 365, 'period_date' => '2026-03-01',
        ]);

        // Mietdauer nur 1.1.–30.6.2026 = 181 Tage von 365 => Zeitfaktor 181/365.
        $result = $this->service()->allocateForLease(
            $managementGroup, $unitA, $r1, $l1, $billingInterval,
            Carbon::parse('2026-01-01'), Carbon::parse('2026-06-30')
        );

        $this->assertEqualsWithDelta(181.0, $result['byStage']['rentalUnit']['total'], 0.01);
        $this->assertEqualsWithDelta(181.0, $result['total'], 0.01);
    }

    public function test_a_full_interval_lease_has_a_time_factor_of_one(): void
    {
        [$managementGroup, $unitA, $r1, $l1, $start, $end] = $this->makeFixture();
        $billingInterval = $managementGroup->billingIntervals()->first();
        $category = $this->attachCategory($managementGroup, 'Mieteinheit-Kosten', 'area');

        $r1->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'amount' => 500, 'period_date' => '2026-03-01',
        ]);

        // Mietdauer = gesamtes Intervall (1.1.–31.12.2026) => Zeitfaktor 1, voller Betrag.
        $result = $this->service()->allocateForLease($managementGroup, $unitA, $r1, $l1, $billingInterval, $start, $end);

        $this->assertEqualsWithDelta(500.0, $result['byStage']['rentalUnit']['total'], 0.01);
    }

    public function test_a_rental_unit_category_at_management_group_level_is_split_evenly_across_the_relevant_units(): void
    {
        [$managementGroup, $unitA, $r1, $l1, $start, $end] = $this->makeFixture();
        $billingInterval = $managementGroup->billingIntervals()->first();
        $category = $this->attachCategory($managementGroup, 'Mieteinheit-Schlüssel-VG', 'rental_unit');

        $managementGroup->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'amount' => 900, 'period_date' => '2026-06-01',
        ]);

        $result = $this->service()->allocateForLease($managementGroup, $unitA, $r1, $l1, $billingInterval, $start, $end);

        // 3 abrechnungsrelevante Mieteinheiten in der VG (R1+R3+R2) => Anteil 1/3 von 900 = 300.
        $this->assertEqualsWithDelta(300.0, $result['byStage']['managementGroup']['total'], 0.01);
    }

    public function test_a_rental_unit_category_at_management_unit_level_is_split_evenly_and_ignores_non_billing_relevant_units(): void
    {
        [$managementGroup, $unitA, $r1, $l1, $start, $end] = $this->makeFixture();
        $billingInterval = $managementGroup->billingIntervals()->first();

        // Eine zusätzliche, NICHT abrechnungsrelevante Mieteinheit in derselben VE darf den
        // Nenner nicht vergrößern – es bleiben 2 relevante (R1+R3).
        $unitA->rentalUnits()->create(['type' => 'Stellplatz', 'size' => 10, 'is_relevant_for_billing' => false]);

        $category = $this->attachCategory($managementGroup, 'Mieteinheit-Schlüssel-VE', 'rental_unit');
        $unitA->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'amount' => 900, 'period_date' => '2026-06-01',
        ]);

        $result = $this->service()->allocateForLease($managementGroup->fresh(), $unitA->fresh(), $r1, $l1, $billingInterval, $start, $end);

        // 2 abrechnungsrelevante Mieteinheiten in der VE (R1+R3) => Anteil 1/2 von 900 = 450.
        $this->assertEqualsWithDelta(450.0, $result['byStage']['managementUnit']['total'], 0.01);
    }

    public function test_a_rental_unit_category_booked_directly_at_the_rental_unit_is_allocated_in_full(): void
    {
        [$managementGroup, $unitA, $r1, $l1, $start, $end] = $this->makeFixture();
        $billingInterval = $managementGroup->billingIntervals()->first();
        $category = $this->attachCategory($managementGroup, 'Mieteinheit-Schlüssel-ME', 'rental_unit');

        $r1->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'amount' => 200, 'period_date' => '2026-06-01',
        ]);

        $result = $this->service()->allocateForLease($managementGroup, $unitA, $r1, $l1, $billingInterval, $start, $end);

        $this->assertEqualsWithDelta(200.0, $result['byStage']['rentalUnit']['total'], 0.01);
    }

    public function test_a_cost_category_not_relevant_for_billing_is_excluded_entirely(): void
    {
        [$managementGroup, $unitA, $r1, $l1, $start, $end] = $this->makeFixture();
        $billingInterval = $managementGroup->billingIntervals()->first();
        $category = CostCategory::create(['name' => 'Zinsen', 'is_relevant_for_billing' => false]);
        $managementGroup->costCategories()->attach($category->id, ['allocation_key' => 'person_count']);

        $managementGroup->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'amount' => 500, 'period_date' => '2026-06-01',
        ]);

        $result = $this->service()->allocateForLease($managementGroup, $unitA, $r1, $l1, $billingInterval, $start, $end);

        $this->assertSame(0.0, $result['total']);
    }

    public function test_precompute_for_statement_returns_the_group_and_unit_level_aggregates(): void
    {
        [$managementGroup, $unitA] = $this->makeFixture();
        $billingInterval = $managementGroup->billingIntervals()->first();
        $this->attachCategory($managementGroup, 'Personen-Kosten', 'person_count');

        $precomputed = $this->service()->precomputeForStatement($managementGroup, $unitA, $billingInterval);

        // Siehe makeFixture()-Docblock: PM(VE)=36, PM(VG)=72, Fläche(VE)=100, Fläche(VG)=200,
        // relevante Mieteinheiten: 2 in Einheit A (R1+R3), 3 in der Verwaltungsgruppe (+R2).
        $this->assertSame(36, $precomputed['personMonthsUnit']);
        $this->assertSame(72, $precomputed['personMonthsGroup']);
        $this->assertSame(100.0, $precomputed['areaUnit']);
        $this->assertSame(200.0, $precomputed['areaGroup']);
        $this->assertSame(2, $precomputed['relevantUnitsUnit']);
        $this->assertSame(3, $precomputed['relevantUnitsGroup']);
        $this->assertSame('person_count', $precomputed['allocationKeys'][$managementGroup->costCategories()->first()->id]);
    }

    public function test_allocate_for_lease_uses_the_given_precomputed_aggregates_instead_of_recomputing_them(): void
    {
        [$managementGroup, $unitA, $r1, $l1, $start, $end] = $this->makeFixture();
        $billingInterval = $managementGroup->billingIntervals()->first();
        $category = $this->attachCategory($managementGroup, 'Personen-Kosten', 'person_count');

        $managementGroup->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $category->id,
            'amount' => 900, 'period_date' => '2026-06-01',
        ]);

        // Deliberately wrong compared to the real DB state (real PM(VG) is 72, see the
        // fixture) – proves allocateForLease() actually uses the passed-in aggregates
        // instead of silently recomputing its own from the database.
        $precomputed = [
            'allocationKeys' => [$category->id => 'person_count'],
            'personMonthsGroup' => 240,
            'personMonthsUnit' => 36,
            'areaGroup' => 200.0,
            'areaUnit' => 100.0,
            'relevantUnitsGroup' => 3,
            'relevantUnitsUnit' => 2,
        ];

        $result = $this->service()->allocateForLease($managementGroup, $unitA, $r1, $l1, $billingInterval, $start, $end, $precomputed);

        // PM(Mieter)=24, PM(VG) laut precomputed=240 => Anteil 0.1 von 900 = 90 (statt der
        // 300, die sich mit dem echten PM(VG)=72 ergäben – siehe das reguläre Pendant
        // dieses Tests oben).
        $this->assertEqualsWithDelta(90.0, $result['byStage']['managementGroup']['total'], 0.01);
    }

    /**
     * Covers suggestedMonthlyUtilityAdvance(): (total / months) / area gives a monthly
     * €/m² rate, rounded UP to the next full 10 Cent, multiplied back by area.
     */
    public function test_suggested_utility_advance_rounds_the_rate_up_to_the_next_ten_cents(): void
    {
        // (738 / 12) / 65.5 = 0.9389...€/m²/Monat -> aufgerundet 1,00 €/m² -> * 65.5 = 65.50 €.
        $this->assertEqualsWithDelta(65.5, $this->service()->suggestedMonthlyUtilityAdvance(738.0, 65.5, 12), 0.001);
    }

    /**
     * Regression: a rate that's already an exact multiple of 0.10 must not be bumped up
     * to the next step by floating-point noise (e.g. 2.5000000001 due to division).
     */
    public function test_suggested_utility_advance_does_not_over_round_an_already_clean_rate(): void
    {
        // (300 / 12) / 60 = 0.4166... -> aufgerundet 0,50 €/m² -> * 60 = 30.00 €.
        $this->assertEqualsWithDelta(30.0, $this->service()->suggestedMonthlyUtilityAdvance(300.0, 60.0, 12), 0.001);

        // A rate exactly at 2.50 €/m² (300*12=3600 total / 12 months / 120 m² = 2.5)
        // must stay at 2.50, not round up to 2.60.
        $this->assertEqualsWithDelta(300.0, $this->service()->suggestedMonthlyUtilityAdvance(3600.0, 120.0, 12), 0.001);
    }

    public function test_suggested_utility_advance_is_zero_when_area_or_months_is_zero(): void
    {
        $this->assertSame(0.0, $this->service()->suggestedMonthlyUtilityAdvance(738.0, 0.0, 12));
        $this->assertSame(0.0, $this->service()->suggestedMonthlyUtilityAdvance(738.0, 65.5, 0));
    }

    /**
     * Konzept.md "##### Berechnung neue Vorauszahlung" Regel b): eine Mietdauer unter
     * 3 Monaten reicht nicht als Datenbasis – die bisherige Vorauszahlung bleibt.
     */
    public function test_suggested_utility_advance_keeps_the_old_value_when_the_lease_is_too_new(): void
    {
        // Roher Vorschlag wäre 65.5 (siehe oben), aber die Mietdauer beträgt nur 2 Monate.
        $suggested = $this->service()->suggestedMonthlyUtilityAdvance(738.0, 65.5, 12, currentAdvance: 50.0, leaseDurationMonths: 2);

        $this->assertSame(50.0, $suggested);
    }

    /**
     * Regel c): eine Reduzierung erfolgt nur, wenn der neue Vorschlag unter 90 % der
     * bisherigen Vorauszahlung liegt.
     */
    public function test_suggested_utility_advance_only_reduces_below_the_ninety_percent_threshold(): void
    {
        // Rohvorschlag 30.0 (siehe oben). 90 % von 32 sind 28.8 -> 30.0 liegt darüber,
        // also bleibt die bisherige Vorauszahlung (32.0) unverändert.
        $this->assertSame(32.0, $this->service()->suggestedMonthlyUtilityAdvance(300.0, 60.0, 12, currentAdvance: 32.0, leaseDurationMonths: 12));

        // 90 % von 40 sind 36.0 -> 30.0 liegt klar darunter, die Reduzierung greift.
        $this->assertSame(30.0, $this->service()->suggestedMonthlyUtilityAdvance(300.0, 60.0, 12, currentAdvance: 40.0, leaseDurationMonths: 12));
    }

    /**
     * Regel d): eine Erhöhung erfolgt nur, wenn der neue Vorschlag mehr als 5 % über
     * der bisherigen Vorauszahlung liegt.
     */
    public function test_suggested_utility_advance_only_increases_above_the_five_percent_threshold(): void
    {
        // Rohvorschlag 65.5 (siehe oben). 105 % von 63 sind 66.15 -> 65.5 liegt
        // darunter, also bleibt die bisherige Vorauszahlung (63.0) unverändert.
        $this->assertSame(63.0, $this->service()->suggestedMonthlyUtilityAdvance(738.0, 65.5, 12, currentAdvance: 63.0, leaseDurationMonths: 12));

        // 105 % von 60 sind 63.0 -> 65.5 liegt klar darüber, die Erhöhung greift.
        $this->assertSame(65.5, $this->service()->suggestedMonthlyUtilityAdvance(738.0, 65.5, 12, currentAdvance: 60.0, leaseDurationMonths: 12));
    }

    /**
     * Baut die Fixtur für vacancyLeaseEntries(): eine Verwaltungseinheit mit R1 (60m²,
     * das ganze Jahr vermietet) und R2 (40m², erst ab 1.7.2026 vermietet – Januar bis
     * Juni 2026 also 181 Tage Leerstand). Eine Kategorie "Heizkosten" (Fläche-Schlüssel)
     * ist sowohl auf Verwaltungseinheits-Ebene (1000 €, Stufe 2) als auch direkt auf R2
     * selbst gebucht (200 €, Stufe 3 – z. B. eine Zählergebühr, die auch bei Leerstand
     * anfällt).
     *
     * @return array{0: ManagementUnit, 1: RentalUnit, 2: BillingInterval, 3: Collection}
     */
    private function makeVacancyFixture(): array
    {
        $user = User::factory()->create();
        $group = Group::create(['name' => 'Leerstand Gruppe', 'owner_id' => $user->id]);
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create(['street' => 'A-Straße', 'postal_code' => '1', 'city' => 'X']);

        $r1 = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 60, 'is_relevant_for_billing' => true]);
        $r2 = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 40, 'is_relevant_for_billing' => true]);

        $l1 = Lease::create(['rental_unit_id' => $r1->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $l1->tenants()->attach(Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B'])->id);

        $l2 = Lease::create(['rental_unit_id' => $r2->id, 'start_date' => '2026-07-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 400]);
        $l2->tenants()->attach(Tenant::create(['first_name' => 'Erika', 'last_name' => 'Musterfrau', 'street' => 'A', 'postal_code' => '1', 'city' => 'B'])->id);

        $billingInterval = $managementGroup->billingIntervals()->first();
        $billingInterval->update(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $heizkosten = $this->attachCategory($managementGroup, 'Heizkosten', 'area');
        $managementUnit->costs()->create(['billing_interval_id' => $billingInterval->id, 'cost_category_id' => $heizkosten->id, 'amount' => 1000, 'period_date' => '2026-06-01']);
        $r2->costs()->create(['billing_interval_id' => $billingInterval->id, 'cost_category_id' => $heizkosten->id, 'amount' => 200, 'period_date' => '2026-06-01']);

        $service = $this->service();
        $precomputed = $service->precomputeForStatement($managementGroup, $managementUnit, $billingInterval);

        $leases = collect([
            ['allocatedCosts' => $service->allocateForLease($managementGroup, $managementUnit, $r1, $l1->fresh(), $billingInterval, Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'), $precomputed)],
            ['allocatedCosts' => $service->allocateForLease($managementGroup, $managementUnit, $r2, $l2->fresh(), $billingInterval, Carbon::parse('2026-07-01'), Carbon::parse('2026-12-31'), $precomputed)],
        ]);

        return [$managementUnit->fresh(), $r2->fresh(), $billingInterval->fresh(), $leases];
    }

    public function test_vacancy_lease_entries_lists_the_gap_of_a_partially_vacant_rental_unit(): void
    {
        [$managementUnit, $r2, $billingInterval] = $this->makeVacancyFixture();

        $entries = $this->service()->vacancyLeaseEntries($managementUnit, $billingInterval);

        $this->assertCount(1, $entries);
        $entry = $entries->first();
        $this->assertTrue($entry['rentalUnit']->is($r2));
        $this->assertTrue($entry['isVacancy']);
        $this->assertSame('Leerstand', $entry['tenantNames']);
        $this->assertSame('2026-01-01', $entry['periodStart']->toDateString());
        $this->assertSame('2026-06-30', $entry['periodEnd']->toDateString());
        $this->assertSame(181, $entry['days']);
    }

    /**
     * Konzept.md "##### Leerstände": eine leerstehende Mieteinheit wird "wie ein
     * Mieter gehandhabt" – ihr Phantom-Mietvertrag läuft durch dieselbe
     * allocateForLease()-Pipeline wie ein echter. Das eigentliche Nutzerziel (Mieter-
     * anteil + Vermieteranteil = tatsächlich gebuchte Kosten) reconciled dadurch von
     * selbst exakt (1000 € Stufe 2 + 200 € Stufe 3 = 1200 €), ohne jede eigene
     * Ergänzungsrechnung.
     */
    public function test_vacancy_lease_entries_reconcile_exactly_when_run_through_the_same_allocation_pipeline(): void
    {
        [$managementUnit, $r2, $billingInterval, $leases] = $this->makeVacancyFixture();
        $managementGroup = $managementUnit->managementGroup;
        $service = $this->service();

        $precomputed = $service->precomputeForStatement($managementGroup, $managementUnit, $billingInterval);
        $vacancyEntry = $service->vacancyLeaseEntries($managementUnit, $billingInterval)->first();
        $vacancyAllocated = $service->allocateForLease(
            $managementGroup, $managementUnit, $r2, $vacancyEntry['lease'], $billingInterval,
            $vacancyEntry['periodStart'], $vacancyEntry['periodEnd'], $precomputed
        );

        $tenantTotal = $leases->sum(fn ($entry) => $entry['allocatedCosts']['byStage']['managementUnit']['total'] + $entry['allocatedCosts']['byStage']['rentalUnit']['total']);
        $vacancyTotal = $vacancyAllocated['byStage']['managementUnit']['total'] + $vacancyAllocated['byStage']['rentalUnit']['total'];

        $this->assertEqualsWithDelta(1200.0, $tenantTotal + $vacancyTotal, 0.001);
        // 1000 € * (40/100 Fläche) * (181/365 Tage) [Stufe 2] + 200 € * (181/365) [Stufe 3].
        $this->assertEqualsWithDelta(198.3561644 + 99.1780822, $vacancyAllocated['byCategory']['Heizkosten'], 0.001);
        // Der 0-Personenhaushalt bekommt beim Abrechnungsschlüssel "Personen" (hier
        // nicht verwendet, aber prinzipiell) konsequent 0 zugeteilt – nicht getestet
        // hier, siehe Docblock von vacancyLeaseEntries().
    }

    public function test_vacancy_lease_entries_are_empty_when_every_relevant_unit_is_fully_occupied(): void
    {
        $user = User::factory()->create();
        $group = Group::create(['name' => 'Voll Belegt Gruppe', 'owner_id' => $user->id]);
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create(['street' => 'A-Straße', 'postal_code' => '1', 'city' => 'X']);
        $r1 = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 60, 'is_relevant_for_billing' => true]);
        $this->makeLease($r1, 'Max', 2);
        $billingInterval = $managementGroup->billingIntervals()->first();
        $billingInterval->update(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $entries = $this->service()->vacancyLeaseEntries($managementUnit->fresh(), $billingInterval->fresh());

        $this->assertCount(0, $entries);
    }

    public function test_vacancy_lease_entries_ignore_a_non_billing_relevant_vacant_rental_unit(): void
    {
        $user = User::factory()->create();
        $group = Group::create(['name' => 'Garage Gruppe', 'owner_id' => $user->id]);
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create(['street' => 'A-Straße', 'postal_code' => '1', 'city' => 'X']);
        // Niemals vermietete Garage, aber nicht abrechnungsrelevant.
        $managementUnit->rentalUnits()->create(['type' => 'Garage', 'size' => 15, 'is_relevant_for_billing' => false]);
        $billingInterval = $managementGroup->billingIntervals()->first();
        $billingInterval->update(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $entries = $this->service()->vacancyLeaseEntries($managementUnit->fresh(), $billingInterval->fresh());

        $this->assertCount(0, $entries);
    }

    /**
     * Konzept.md "Zu diesem Leerstand müssen auch Forderungen ... verbucht werden."
     */
    public function test_vacancy_lease_entries_include_the_rental_units_settlement_claims_for_this_interval(): void
    {
        [$managementUnit, $r2, $billingInterval] = $this->makeVacancyFixture();
        $claim = $r2->settlementClaims()->create(['billing_interval_id' => $billingInterval->id, 'date' => '2026-03-01', 'amount' => 42.0]);
        // Eine Forderung eines anderen Abrechnungsintervalls darf nicht mit auftauchen.
        $otherInterval = $managementUnit->managementGroup->billingIntervals()->create(['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
        $r2->settlementClaims()->create(['billing_interval_id' => $otherInterval->id, 'date' => '2025-03-01', 'amount' => 999.0]);

        $entry = $this->service()->vacancyLeaseEntries($managementUnit, $billingInterval)->first();

        $this->assertCount(1, $entry['claims']);
        $this->assertTrue($entry['claims']->first()->is($claim));
    }
}
