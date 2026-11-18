<?php

namespace Tests\Unit;

use App\Models\AccountTransfer;
use App\Models\CostCategory;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\InvoiceIssuer;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\RentalUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FinancialOverviewService;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FinancialOverviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeRentalUnit(): RentalUnit
    {
        return $this->makeManagementUnit()->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true,
        ]);
    }

    private function makeManagementUnit(): ManagementUnit
    {
        $user = User::factory()->create();
        $group = Group::create(['name' => 'Test Gruppe', 'owner_id' => $user->id]);
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);

        return $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
    }

    public function test_occupancy_matrix_marks_vacant_rental_units_with_no_tenant(): void
    {
        $rentalUnit = $this->makeRentalUnit();

        $rows = (new FinancialOverviewService)->occupancyMatrix(collect([$rentalUnit]), 2026);

        $this->assertCount(1, $rows);
        $this->assertSame($rentalUnit->label(), $rows[0]['rental_unit']);

        foreach ($rows[0]['months'] as $tenantNames) {
            $this->assertNull($tenantNames);
        }
    }

    public function test_occupancy_matrix_shows_the_tenant_during_the_lease_period_only(): void
    {
        $rentalUnit = $this->makeRentalUnit();

        $tenant = Tenant::create([
            'first_name' => 'Max', 'last_name' => 'Mustermann',
            'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ]);

        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id,
            'start_date' => '2026-04-01',
            'end_date' => '2026-06-30',
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);

        $rentalUnit->refresh()->load('leases.tenants');

        $rows = (new FinancialOverviewService)->occupancyMatrix(collect([$rentalUnit]), 2026);

        $months = $rows[0]['months'];
        $this->assertNull($months[3]);
        $this->assertSame('Max Mustermann', $months[4]);
        $this->assertSame('Max Mustermann', $months[6]);
        $this->assertNull($months[7]);
    }

    public function test_occupancy_plan_shows_the_occupant_count_of_the_lease_interval_active_that_month(): void
    {
        $rentalUnit = $this->makeRentalUnit();
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);
        $lease->currentLeaseInterval()->update(['occupant_count' => 2, 'end_date' => '2026-06-30']);
        $lease->leaseIntervals()->create(['start_date' => '2026-07-01', 'rent_amount' => 500, 'occupant_count' => 3]);

        $rentalUnit->refresh()->load('leases.leaseIntervals');

        $plan = (new FinancialOverviewService)->occupancyPlan(collect([$rentalUnit]), 2026);

        $this->assertCount(1, $plan['rows']);
        $row = $plan['rows'][0];
        $this->assertSame($rentalUnit->label(), $row['rental_unit']);
        $this->assertSame(2, $row['months'][6]);
        $this->assertSame(3, $row['months'][7]);
        $this->assertSame(2 * 6 + 3 * 6, $row['total']);
        $this->assertSame(2, $plan['totals'][6]);
        $this->assertSame(3, $plan['totals'][7]);
    }

    public function test_occupancy_plan_omits_rental_units_that_are_vacant_the_whole_year(): void
    {
        $vacant = $this->makeRentalUnit();
        $occupiedUnit = $this->makeManagementUnit()->rentalUnits()->create(['type' => 'Wohnung', 'size' => 40, 'is_relevant_for_billing' => true]);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create([
            'rental_unit_id' => $occupiedUnit->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);
        $lease->currentLeaseInterval()->update(['occupant_count' => 1]);
        $occupiedUnit->refresh()->load('leases.leaseIntervals');

        $plan = (new FinancialOverviewService)->occupancyPlan(collect([$vacant, $occupiedUnit]), 2026);

        $this->assertCount(1, $plan['rows']);
        $this->assertSame($occupiedUnit->label(), $plan['rows'][0]['rental_unit']);
    }

    public function test_occupancy_plan_totals_sum_every_rental_units_months(): void
    {
        $unitA = $this->makeRentalUnit();
        $unitB = $this->makeManagementUnit()->rentalUnits()->create(['type' => 'Wohnung', 'size' => 40, 'is_relevant_for_billing' => true]);

        $tenantA = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $leaseA = Lease::create(['rental_unit_id' => $unitA->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $leaseA->tenants()->attach($tenantA->id);
        $leaseA->currentLeaseInterval()->update(['occupant_count' => 1]);

        $tenantB = Tenant::create(['first_name' => 'Erika', 'last_name' => 'Musterfrau', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $leaseB = Lease::create(['rental_unit_id' => $unitB->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 400]);
        $leaseB->tenants()->attach($tenantB->id);
        $leaseB->currentLeaseInterval()->update(['occupant_count' => 4]);

        $unitA->refresh()->load('leases.leaseIntervals');
        $unitB->refresh()->load('leases.leaseIntervals');

        $plan = (new FinancialOverviewService)->occupancyPlan(collect([$unitA, $unitB]), 2026);

        $this->assertSame(5, $plan['totals'][1]);
        $this->assertSame(60, $plan['grandTotal']);
    }

    public function test_person_months_sums_occupant_counts_across_rental_units_and_the_full_month_range(): void
    {
        $unitA = $this->makeRentalUnit();
        $unitB = $this->makeManagementUnit()->rentalUnits()->create(['type' => 'Wohnung', 'size' => 40, 'is_relevant_for_billing' => true]);

        $tenantA = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $leaseA = Lease::create(['rental_unit_id' => $unitA->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $leaseA->tenants()->attach($tenantA->id);
        $leaseA->currentLeaseInterval()->update(['occupant_count' => 2]);

        $tenantB = Tenant::create(['first_name' => 'Erika', 'last_name' => 'Musterfrau', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $leaseB = Lease::create(['rental_unit_id' => $unitB->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 400]);
        $leaseB->tenants()->attach($tenantB->id);
        $leaseB->currentLeaseInterval()->update(['occupant_count' => 1]);

        $unitA->refresh()->load('leases.leaseIntervals');
        $unitB->refresh()->load('leases.leaseIntervals');

        $total = (new FinancialOverviewService)->personMonths(
            collect([$unitA, $unitB]),
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-06-30')
        );

        // (2 + 1) Personen je Monat * 6 Monate (Januar bis Juni) = 18.
        $this->assertSame(18, $total);
    }

    public function test_person_months_counts_zero_for_months_before_the_lease_started(): void
    {
        $rentalUnit = $this->makeRentalUnit();
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create(['rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-04-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $lease->tenants()->attach($tenant->id);
        $lease->currentLeaseInterval()->update(['occupant_count' => 2]);

        $rentalUnit->refresh()->load('leases.leaseIntervals');

        // Januar bis Juni: leer Januar-März (0), belegt April-Juni (2 je Monat) = 0+0+0+2+2+2 = 6.
        $total = (new FinancialOverviewService)->personMonths(
            collect([$rentalUnit]),
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-06-30')
        );

        $this->assertSame(6, $total);
    }

    /**
     * "Mieter und Mieteinheit"-Ansicht (incomeMatrix()): ein Mieter, der für dieselbe
     * Mieteinheit einen neuen Mietvertrag bekommt (z. B. Nachfolgevertrag eines
     * Vorvertrags), landet trotz zweier unterschiedlicher Lease-Datensätze in EINER
     * Zeile, solange Mieter UND Mieteinheit übereinstimmen – die Monate der beiden
     * (nicht überlappenden) Verträge werden zusammengeführt statt zwei Zeilen zu zeigen.
     */
    public function test_income_matrix_groups_leases_by_tenant_and_rental_unit_even_across_different_mietvertraege(): void
    {
        $rentalUnit = $this->makeRentalUnit();
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $oldLease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01', 'end_date' => '2026-06-30',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 400,
        ]);
        $oldLease->tenants()->attach($tenant->id);

        $newLease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-07-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 450,
        ]);
        $newLease->tenants()->attach($tenant->id);

        $leases = collect([
            $oldLease->fresh(['leaseIntervals', 'tenants', 'rentalUnit']),
            $newLease->fresh(['leaseIntervals', 'tenants', 'rentalUnit']),
        ]);

        $result = (new FinancialOverviewService)->incomeMatrix($leases, 2026);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('Max Mustermann', $result['rows'][0]['tenant']);
        $this->assertSame($rentalUnit->label(), $result['rows'][0]['rental_unit']);
        $this->assertSame(400.0, $result['rows'][0]['months'][1]);
        $this->assertSame(450.0, $result['rows'][0]['months'][7]);
        $this->assertSame(5100.0, $result['rows'][0]['total']);
    }

    public function test_income_matrix_by_tenant_aggregates_multiple_rental_units_of_the_same_tenant(): void
    {
        $managementUnit = $this->makeManagementUnit();
        $rentalUnitA = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true]);
        $rentalUnitB = $managementUnit->rentalUnits()->create(['type' => 'Stellplatz', 'size' => null, 'is_relevant_for_billing' => false]);

        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $leaseA = Lease::create(['rental_unit_id' => $rentalUnitA->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $leaseA->tenants()->attach($tenant->id);
        $leaseA->currentLeaseInterval()->update(['utility_advance_payment' => 100]);

        $leaseB = Lease::create(['rental_unit_id' => $rentalUnitB->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 30]);
        $leaseB->tenants()->attach($tenant->id);

        $leases = collect([
            $leaseA->fresh(['leaseIntervals', 'tenants']),
            $leaseB->fresh(['leaseIntervals', 'tenants']),
        ]);

        $result = (new FinancialOverviewService)->incomeMatrixByTenant($leases, 2026);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('Max Mustermann', $result['rows'][0]['tenant']);
        $this->assertSame(630.0, $result['rows'][0]['months'][1]);
    }

    public function test_income_matrix_by_tenant_split_keeps_rent_and_utility_separate(): void
    {
        $rentalUnit = $this->makeRentalUnit();
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $lease = Lease::create(['rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $lease->tenants()->attach($tenant->id);
        $lease->currentLeaseInterval()->update(['utility_advance_payment' => 120]);

        $result = (new FinancialOverviewService)->incomeMatrixByTenantSplit(
            collect([$lease->fresh(['leaseIntervals', 'tenants'])]),
            2026
        );

        $this->assertSame(500.0, $result['rows'][0]['months'][1]['rent']);
        $this->assertSame(120.0, $result['rows'][0]['months'][1]['utility']);
        $this->assertSame(6000.0, $result['rows'][0]['totalRent']);
        $this->assertSame(1440.0, $result['rows'][0]['totalUtility']);
    }

    public function test_booked_income_matrix_only_counts_rent_payments_with_a_booking_date(): void
    {
        $rentalUnit = $this->makeRentalUnit();
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $lease = Lease::create(['rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $lease->tenants()->attach($tenant->id);

        $interval = $lease->currentLeaseInterval();
        $interval->rentPayments()->create(['period_date' => '2026-01-01', 'amount' => 500, 'booking_date' => '2026-01-05']);
        $interval->rentPayments()->create(['period_date' => '2026-02-01', 'amount' => 500, 'booking_date' => null]);
        $interval->rentPayments()->create(['period_date' => '2026-03-01', 'amount' => 500, 'booking_date' => '2026-03-04']);

        $result = (new FinancialOverviewService)->bookedIncomeMatrix(
            collect([$lease->fresh(['leaseIntervals.rentPayments', 'tenants'])]),
            2026
        );

        $this->assertSame(500.0, $result['rows'][0]['months'][1]);
        $this->assertSame(0.0, $result['rows'][0]['months'][2]);
        $this->assertSame(500.0, $result['rows'][0]['months'][3]);
        $this->assertSame(1000.0, $result['rows'][0]['total']);
        $this->assertSame(1000.0, $result['grandTotal']);
    }

    /**
     * Same tenant+Mieteinheit grouping as incomeMatrix() (see above), but for the
     * "Gebuchte Mieteinnahmen" counterpart.
     */
    public function test_booked_income_matrix_groups_leases_by_tenant_and_rental_unit_even_across_different_mietvertraege(): void
    {
        $rentalUnit = $this->makeRentalUnit();
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $oldLease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01', 'end_date' => '2026-06-30',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 400,
        ]);
        $oldLease->tenants()->attach($tenant->id);
        $oldLease->currentLeaseInterval()->rentPayments()->create(['period_date' => '2026-01-01', 'amount' => 400, 'booking_date' => '2026-01-03']);

        $newLease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-07-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 450,
        ]);
        $newLease->tenants()->attach($tenant->id);
        $newLease->currentLeaseInterval()->rentPayments()->create(['period_date' => '2026-08-01', 'amount' => 450, 'booking_date' => '2026-08-02']);

        $leases = collect([
            $oldLease->fresh(['leaseIntervals.rentPayments', 'tenants', 'rentalUnit']),
            $newLease->fresh(['leaseIntervals.rentPayments', 'tenants', 'rentalUnit']),
        ]);

        $result = (new FinancialOverviewService)->bookedIncomeMatrix($leases, 2026);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('Max Mustermann', $result['rows'][0]['tenant']);
        $this->assertSame($rentalUnit->label(), $result['rows'][0]['rental_unit']);
        $this->assertSame(400.0, $result['rows'][0]['months'][1]);
        $this->assertSame(450.0, $result['rows'][0]['months'][8]);
        $this->assertSame(850.0, $result['rows'][0]['total']);
    }

    public function test_booked_income_by_rental_unit_type_groups_by_type_and_ignores_unbooked_payments(): void
    {
        $managementUnit = $this->makeManagementUnit();
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true]);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $lease = Lease::create(['rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $lease->tenants()->attach($tenant->id);

        $interval = $lease->currentLeaseInterval();
        $interval->rentPayments()->create(['period_date' => '2026-01-01', 'amount' => 500, 'booking_date' => '2026-01-05']);
        $interval->rentPayments()->create(['period_date' => '2026-02-01', 'amount' => 500, 'booking_date' => null]);

        $result = (new FinancialOverviewService)->bookedIncomeByRentalUnitType(
            collect([$lease->fresh(['leaseIntervals.rentPayments', 'tenants'])]),
            2026
        );

        $this->assertSame(500.0, $result['types']['Wohnung']['months'][1]);
        $this->assertSame(0.0, $result['types']['Wohnung']['months'][2]);
        $this->assertSame(500.0, $result['types']['Wohnung']['total']);
        $this->assertSame(500.0, $result['grandTotal']);
    }

    public function test_booked_income_matrix_by_tenant_aggregates_across_leases_like_its_forecast_counterpart(): void
    {
        $managementUnit = $this->makeManagementUnit();
        $rentalUnitA = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true]);
        $rentalUnitB = $managementUnit->rentalUnits()->create(['type' => 'Stellplatz', 'size' => null, 'is_relevant_for_billing' => false]);

        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $leaseA = Lease::create(['rental_unit_id' => $rentalUnitA->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $leaseA->tenants()->attach($tenant->id);
        $leaseA->currentLeaseInterval()->rentPayments()->create(['period_date' => '2026-01-01', 'amount' => 500, 'booking_date' => '2026-01-05']);

        $leaseB = Lease::create(['rental_unit_id' => $rentalUnitB->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 30]);
        $leaseB->tenants()->attach($tenant->id);
        $leaseB->currentLeaseInterval()->rentPayments()->create(['period_date' => '2026-01-01', 'amount' => 30, 'booking_date' => '2026-01-05']);
        $leaseB->currentLeaseInterval()->rentPayments()->create(['period_date' => '2026-02-01', 'amount' => 30, 'booking_date' => null]);

        $leases = collect([
            $leaseA->fresh(['leaseIntervals.rentPayments', 'tenants']),
            $leaseB->fresh(['leaseIntervals.rentPayments', 'tenants']),
        ]);

        $result = (new FinancialOverviewService)->bookedIncomeMatrixByTenant($leases, 2026);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('Max Mustermann', $result['rows'][0]['tenant']);
        $this->assertSame(530.0, $result['rows'][0]['months'][1]);
        $this->assertSame(0.0, $result['rows'][0]['months'][2]);
    }

    public function test_booked_income_matrix_by_tenant_split_keeps_rent_and_utility_separate(): void
    {
        $rentalUnit = $this->makeRentalUnit();
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $lease = Lease::create(['rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $lease->tenants()->attach($tenant->id);
        $interval = $lease->currentLeaseInterval();
        $interval->update(['utility_advance_payment' => 120]);
        $interval->rentPayments()->create([
            'period_date' => '2026-01-01', 'amount' => 620, 'rent_amount' => 500, 'utility_amount' => 120, 'booking_date' => '2026-01-05',
        ]);

        $result = (new FinancialOverviewService)->bookedIncomeMatrixByTenantSplit(
            collect([$lease->fresh(['leaseIntervals.rentPayments', 'tenants'])]),
            2026
        );

        $this->assertSame(500.0, $result['rows'][0]['months'][1]['rent']);
        $this->assertSame(120.0, $result['rows'][0]['months'][1]['utility']);
        $this->assertSame(0.0, $result['rows'][0]['months'][2]['rent']);
    }

    public function test_tax_overview_income_by_floor_buckets_erdgeschoss_1_og_and_more_than_3_og_separately(): void
    {
        $managementUnit = $this->makeManagementUnit();
        $erdgeschoss = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 50, 'floor' => 0, 'description' => 'EG']);
        $ersteEtage = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 50, 'floor' => 1, 'description' => '1.OG']);
        $vierteEtage = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 50, 'floor' => 4, 'description' => '4.OG']);
        $ohneEtage = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 50, 'floor' => null, 'description' => 'Ohne Etage']);

        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $leases = collect();

        foreach ([$erdgeschoss, $ersteEtage, $vierteEtage, $ohneEtage] as $rentalUnit) {
            $lease = Lease::create(['rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 100]);
            $lease->tenants()->attach($tenant->id);
            $lease->currentLeaseInterval()->rentPayments()->create([
                'period_date' => '2026-01-01', 'amount' => 100, 'rent_amount' => 100, 'utility_amount' => 0, 'booking_date' => '2026-01-05',
            ]);
            $leases->push($lease->fresh(['leaseIntervals.rentPayments', 'tenants', 'rentalUnit', 'settlementClaims']));
        }

        $result = (new FinancialOverviewService)->taxOverviewIncomeByFloor($leases, 2026);

        // Numeric string keys ('0', '1') are normalized to int by PHP array semantics.
        $this->assertSame([0, 1, '4+', 'none'], array_keys($result['floors']));
        $this->assertSame('Erdgeschoss', $result['floors']['0']['label']);
        $this->assertSame('>3. OG', $result['floors']['4+']['label']);
        $this->assertSame(100.0, $result['floors']['0']['rent']);
        $this->assertSame(400.0, $result['totals']['rent']);
    }

    /**
     * Zuflussprinzip (§ 11 EStG) applies to Nachzahlungen/Rückzahlungen too: only
     * claims actually BOOKED (booking_date) in the given year count, bucketed by
     * booking_date's year – not the claim's nominal "Datum", and not at all while
     * still open (booking_date null, "gilt als noch offen" per Konzept.md).
     */
    public function test_tax_overview_income_by_floor_only_counts_settlement_claims_booked_in_the_given_year(): void
    {
        $rentalUnit = $this->makeRentalUnit();
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create(['rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $lease->tenants()->attach($tenant->id);
        $lease->currentLeaseInterval()->rentPayments()->create([
            'period_date' => '2026-01-01', 'amount' => 500, 'rent_amount' => 500, 'utility_amount' => 0, 'booking_date' => '2026-01-05',
        ]);

        $billingInterval = $rentalUnit->managementUnit->managementGroup->billingIntervals()->first();
        // Booked in 2026 -> counts.
        $lease->settlementClaims()->create(['billing_interval_id' => $billingInterval->id, 'amount' => 200, 'date' => '2026-03-01', 'booking_date' => '2026-03-10']);
        // Nominal "Datum" is 2026, but actually booked (paid) in 2025 -> counts toward 2025, not 2026.
        $lease->settlementClaims()->create(['billing_interval_id' => $billingInterval->id, 'amount' => -999, 'date' => '2026-01-01', 'booking_date' => '2025-12-20']);
        // Still open (no booking_date) -> not yet realized, counts toward neither year.
        $lease->settlementClaims()->create(['billing_interval_id' => $billingInterval->id, 'amount' => 555, 'date' => '2026-03-01']);

        $result = (new FinancialOverviewService)->taxOverviewIncomeByFloor(
            collect([$lease->fresh(['leaseIntervals.rentPayments', 'tenants', 'rentalUnit', 'settlementClaims'])]),
            2026
        );

        $this->assertSame(200.0, $result['totals']['settlement']);
    }

    /**
     * Zuflussprinzip regression test: a tenant ("Probe"-Fall) pays January's rent
     * already in the preceding December as an advance – the RentPayment's
     * "period_date" (what the payment is FOR) falls in the new year, but its
     * "booking_date" (when the money was actually received) falls in the old one.
     * The Steuerübersicht must count it toward the year it was booked, not the year
     * its period nominally belongs to.
     */
    public function test_tax_overview_income_by_floor_counts_an_advance_payment_toward_the_year_it_was_booked_not_its_period(): void
    {
        $rentalUnit = $this->makeRentalUnit();
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create(['rental_unit_id' => $rentalUnit->id, 'start_date' => '2022-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $lease->tenants()->attach($tenant->id);
        $interval = $lease->currentLeaseInterval();

        // January 2023's rent, paid (booked) in December 2022 already.
        $interval->rentPayments()->create([
            'period_date' => '2023-01-01', 'amount' => 500, 'rent_amount' => 400, 'utility_amount' => 100, 'booking_date' => '2022-12-28',
        ]);

        $leases = collect([$lease->fresh(['leaseIntervals.rentPayments', 'tenants', 'rentalUnit', 'settlementClaims'])]);

        $result2022 = (new FinancialOverviewService)->taxOverviewIncomeByFloor($leases, 2022);
        $result2023 = (new FinancialOverviewService)->taxOverviewIncomeByFloor($leases, 2023);

        $this->assertSame(400.0, $result2022['totals']['rent']);
        $this->assertSame(100.0, $result2022['totals']['utility']);
        $this->assertSame(0.0, $result2023['totals']['rent']);
        $this->assertSame(0.0, $result2023['totals']['utility']);
    }

    public function test_tax_overview_income_by_floor_ignores_a_lease_not_active_during_the_given_year(): void
    {
        $rentalUnit = $this->makeRentalUnit();
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create(['rental_unit_id' => $rentalUnit->id, 'start_date' => '2027-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $lease->tenants()->attach($tenant->id);

        $result = (new FinancialOverviewService)->taxOverviewIncomeByFloor(
            collect([$lease->fresh(['leaseIntervals.rentPayments', 'tenants', 'rentalUnit', 'settlementClaims'])]),
            2026
        );

        $this->assertSame([], $result['floors']);
        $this->assertSame(0.0, $result['totals']['rent']);
    }

    /**
     * Zuflussprinzip (§ 11 EStG): a Betriebskostennachzahlung/-rückzahlung can be
     * booked well after the Mietvertrag itself already ended (der Mieter ist längst
     * ausgezogen, bevor die Nachzahlung tatsächlich verbucht wird) and must still count
     * toward the year it was booked – the Mietvertrag must not be dropped outright just
     * because it wasn't "aktiv" (rent-wise) during that year (Nutzerhinweis: "Im
     * Steuerjahr 2025 ist die Betriebskostennachzahlung von Probe nicht enthalten.").
     */
    public function test_tax_overview_income_by_floor_still_counts_a_settlement_claim_booked_after_the_lease_ended(): void
    {
        $rentalUnit = $this->makeRentalUnit();
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Probe', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2022-07-01', 'end_date' => '2024-02-29',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);

        $billingInterval = $rentalUnit->managementUnit->managementGroup->billingIntervals()->first();
        $lease->settlementClaims()->create([
            'billing_interval_id' => $billingInterval->id, 'amount' => 856.35,
            'date' => '2024-12-21', 'booking_date' => '2025-04-16',
        ]);

        $result = (new FinancialOverviewService)->taxOverviewIncomeByFloor(
            collect([$lease->fresh(['leaseIntervals.rentPayments', 'tenants', 'rentalUnit', 'settlementClaims'])]),
            2025
        );

        $this->assertSame(856.35, $result['totals']['settlement']);
        $this->assertSame(0.0, $result['totals']['rent']);
        $label = $result['floors']['none']['leases'][0]['label'];
        $this->assertStringContainsString('01.07.2022–29.02.2024', $label);
    }

    public function test_tax_overview_income_by_floors_lease_row_label_shows_the_partial_period_within_the_year(): void
    {
        $rentalUnit = $this->makeRentalUnit();
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-04-01', 'end_date' => '2026-09-30',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);
        $lease->currentLeaseInterval()->rentPayments()->create([
            'period_date' => '2026-04-01', 'amount' => 500, 'rent_amount' => 500, 'utility_amount' => 0, 'booking_date' => '2026-04-05',
        ]);

        $result = (new FinancialOverviewService)->taxOverviewIncomeByFloor(
            collect([$lease->fresh(['leaseIntervals.rentPayments', 'tenants', 'rentalUnit', 'settlementClaims'])]),
            2026
        );

        $label = $result['floors']['none']['leases'][0]['label'];
        $this->assertStringContainsString('01.04.–30.09.2026', $label);
    }

    /**
     * Konzept.md "### Mietvertrag": a Mietvertrag coupled to another one via
     * "Mietvertrag: <ref>" (e.g. a Stellplatz coupled to the tenant's Wohnung of the
     * same Verwaltungseinheit) must NOT show up as its own Steuerübersicht row – its
     * Mieteinnahmen/Nebenkosten are summed into the referenced Mietvertrag's row/floor
     * instead, even though the coupled lease's own rentalUnit has a different (or no)
     * Etage.
     */
    public function test_tax_overview_income_by_floor_folds_a_coupled_leases_income_into_the_referenced_lease(): void
    {
        $managementUnit = $this->makeManagementUnit();
        $wohnung = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 50, 'floor' => 1, 'description' => '1.OG']);
        $stellplatz = $managementUnit->rentalUnits()->create([
            'type' => 'Stellplatz', 'size' => 12, 'floor' => null, 'description' => 'Stellplatz', 'is_relevant_for_billing' => false,
        ]);

        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Testmann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $wohnungLease = Lease::create(['rental_unit_id' => $wohnung->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $wohnungLease->tenants()->attach($tenant->id);
        $wohnungLease->currentLeaseInterval()->rentPayments()->create([
            'period_date' => '2026-01-01', 'amount' => 500, 'rent_amount' => 500, 'utility_amount' => 0, 'booking_date' => '2026-01-05',
        ]);

        $stellplatzLease = Lease::create([
            'rental_unit_id' => $stellplatz->id, 'coupled_to_lease_id' => $wohnungLease->id,
            'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 30,
        ]);
        $stellplatzLease->tenants()->attach($tenant->id);
        $stellplatzLease->currentLeaseInterval()->rentPayments()->create([
            'period_date' => '2026-01-01', 'amount' => 30, 'rent_amount' => 30, 'utility_amount' => 0, 'booking_date' => '2026-01-05',
        ]);

        $leases = collect([$wohnungLease, $stellplatzLease])
            ->map(fn ($lease) => $lease->fresh(['leaseIntervals.rentPayments', 'tenants', 'rentalUnit', 'settlementClaims']));

        $result = (new FinancialOverviewService)->taxOverviewIncomeByFloor($leases, 2026);

        // Numeric string keys ('1') are normalized to int by PHP array semantics.
        $this->assertSame([1], array_keys($result['floors']));
        $this->assertCount(1, $result['floors']['1']['leases']);
        $this->assertSame(530.0, $result['floors']['1']['rent']);
        $this->assertSame(530.0, $result['totals']['rent']);
    }

    /**
     * If the referenced Mietvertrag isn't itself active during the given year (e.g. it
     * already ended), the coupled lease's income must still show up SOMEWHERE rather
     * than silently vanishing – it falls back to being listed under its own floor.
     */
    public function test_tax_overview_income_by_floor_lists_a_coupled_lease_on_its_own_when_its_target_is_inactive_that_year(): void
    {
        $managementUnit = $this->makeManagementUnit();
        $wohnung = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 50, 'floor' => 1, 'description' => '1.OG']);
        $stellplatz = $managementUnit->rentalUnits()->create([
            'type' => 'Stellplatz', 'size' => 12, 'floor' => null, 'description' => 'Stellplatz', 'is_relevant_for_billing' => false,
        ]);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Testmann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        // The referenced Mietvertrag only starts the FOLLOWING year -> not active in 2026.
        $wohnungLease = Lease::create(['rental_unit_id' => $wohnung->id, 'start_date' => '2027-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);

        $stellplatzLease = Lease::create([
            'rental_unit_id' => $stellplatz->id, 'coupled_to_lease_id' => $wohnungLease->id,
            'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 30,
        ]);
        $stellplatzLease->tenants()->attach($tenant->id);
        $stellplatzLease->currentLeaseInterval()->rentPayments()->create([
            'period_date' => '2026-01-01', 'amount' => 30, 'rent_amount' => 30, 'utility_amount' => 0, 'booking_date' => '2026-01-05',
        ]);

        $leases = collect([$wohnungLease, $stellplatzLease])
            ->map(fn ($lease) => $lease->fresh(['leaseIntervals.rentPayments', 'tenants', 'rentalUnit', 'settlementClaims']));

        $result = (new FinancialOverviewService)->taxOverviewIncomeByFloor($leases, 2026);

        $this->assertSame(30.0, $result['floors']['none']['rent']);
    }

    /**
     * Konzept.md "### Mietvertrag" / BillingStatementLeaseResolver: a Vorvertrag gets no
     * row of its own in the Steuerübersicht – the real Mietvertrag immediately following
     * it on the same Mieteinheit absorbs its Mieteinnahmen, and the displayed period
     * extends back over it.
     */
    public function test_tax_overview_income_by_floor_combines_a_vorvertrag_with_its_succeeding_lease(): void
    {
        $rentalUnit = $this->makeRentalUnit();
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $preContract = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-03-01', 'end_date' => '2026-03-31',
            'is_pre_contract' => true, 'rent_adjustment_type' => 'fixed', 'cold_rent' => 100,
        ]);
        $preContract->tenants()->attach($tenant->id);
        $preContract->currentLeaseInterval()->rentPayments()->create([
            'period_date' => '2026-03-01', 'amount' => 100, 'rent_amount' => 100, 'utility_amount' => 0, 'booking_date' => '2026-03-05',
        ]);

        $mainLease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-04-01', 'end_date' => '2026-09-30',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $mainLease->tenants()->attach($tenant->id);
        $mainLease->currentLeaseInterval()->rentPayments()->create([
            'period_date' => '2026-04-01', 'amount' => 500, 'rent_amount' => 500, 'utility_amount' => 0, 'booking_date' => '2026-04-05',
        ]);

        $leases = collect([$preContract, $mainLease])
            ->map(fn ($lease) => $lease->fresh(['leaseIntervals.rentPayments', 'tenants', 'rentalUnit', 'settlementClaims']));

        $result = (new FinancialOverviewService)->taxOverviewIncomeByFloor($leases, 2026);

        $this->assertCount(1, $result['floors']['none']['leases']);
        $this->assertSame(600.0, $result['floors']['none']['rent']);
        $this->assertSame(600.0, $result['totals']['rent']);
        $this->assertStringContainsString('01.03.–30.09.2026', $result['floors']['none']['leases'][0]['label']);
    }

    /**
     * If a Vorvertrag's following Mietvertrag isn't itself active during the given year,
     * the Vorvertrag's income must still show up SOMEWHERE rather than silently
     * vanishing – it falls back to being listed under its own floor.
     */
    public function test_tax_overview_income_by_floor_lists_a_vorvertrag_on_its_own_when_no_successor_lease_exists(): void
    {
        $rentalUnit = $this->makeRentalUnit();
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $preContract = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-03-01', 'end_date' => '2026-03-31',
            'is_pre_contract' => true, 'rent_adjustment_type' => 'fixed', 'cold_rent' => 100,
        ]);
        $preContract->tenants()->attach($tenant->id);
        $preContract->currentLeaseInterval()->rentPayments()->create([
            'period_date' => '2026-03-01', 'amount' => 100, 'rent_amount' => 100, 'utility_amount' => 0, 'booking_date' => '2026-03-05',
        ]);

        $result = (new FinancialOverviewService)->taxOverviewIncomeByFloor(
            collect([$preContract->fresh(['leaseIntervals.rentPayments', 'tenants', 'rentalUnit', 'settlementClaims'])]),
            2026
        );

        $this->assertSame(100.0, $result['floors']['none']['rent']);
    }

    public function test_account_balances_carries_the_initial_balance_into_the_opening_row(): void
    {
        $managementUnit = $this->makeManagementUnit();
        $managementGroup = $managementUnit->managementGroup;
        $account = $managementGroup->accounts()->create(['name' => 'Girokonto', 'initial_balance' => 1000]);

        $result = (new FinancialOverviewService)->accountBalances($managementGroup, 2026);

        $this->assertSame(1000.0, $result['rows'][0]['opening']);
        $this->assertSame(1000.0, $result['rows'][0]['closing']);
        $this->assertSame(1000.0, $result['openingTotal']);
        $this->assertSame(1000.0, $result['closingTotal']);
    }

    public function test_expenses_by_category_are_sorted_by_name_regardless_of_input_order(): void
    {
        $this->seed(CostCategorySeeder::class);

        $managementUnit = $this->makeManagementUnit();
        $managementGroup = $managementUnit->managementGroup;
        $billingInterval = $managementGroup->billingIntervals()->first();
        $zinsen = CostCategory::where('name', 'Zinsen')->first();
        $beleuchtung = CostCategory::where('name', 'Beleuchtung')->first();

        // Insertion order deliberately differs between the "prognostiziert" (all costs)
        // and "gebucht" (booked-only) subsets: the first Zinsen-Kosten is still open, so
        // it's absent from the booked subset, whose first entry is Beleuchtung instead.
        $openZinsen = $managementGroup->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $zinsen->id,
            'amount' => 100, 'period_date' => '2026-01-15',
        ]);
        $bookedBeleuchtung = $managementGroup->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $beleuchtung->id,
            'amount' => 50, 'period_date' => '2026-02-15', 'booking_date' => '2026-02-15',
        ]);
        $bookedZinsen = $managementGroup->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $zinsen->id,
            'amount' => 80, 'period_date' => '2026-03-15', 'booking_date' => '2026-03-15',
        ]);

        $service = new FinancialOverviewService;
        $allCosts = collect([$openZinsen, $bookedBeleuchtung, $bookedZinsen]);
        $bookedCosts = $allCosts->filter(fn ($c) => $c->booking_date !== null);

        $prognostiziert = $service->expensesByCategory($allCosts, 2026);
        $gebucht = $service->expensesByCategory($bookedCosts, 2026);

        $this->assertSame(['Beleuchtung', 'Zinsen'], array_column($prognostiziert['categories'], 'name'));
        $this->assertSame(['Beleuchtung', 'Zinsen'], array_column($gebucht['categories'], 'name'));
    }

    public function test_expenses_by_category_with_booking_date_buckets_by_the_real_booking_month_not_period_date(): void
    {
        $this->seed(CostCategorySeeder::class);

        $managementUnit = $this->makeManagementUnit();
        $managementGroup = $managementUnit->managementGroup;
        $billingInterval = $managementGroup->billingIntervals()->first();
        $zinsen = CostCategory::where('name', 'Zinsen')->first();

        // Wie bei "Musterbank Zinsen und Tilgung": zwei echte Buchungen im Januar
        // (2.1. und 30.1.), aber die reguläre Monatsprognose (period_date) ordnet die
        // zweite Buchung positional dem Februar-Slot zu, damit die Prognose lückenlos
        // bleibt. Für "Gebuchte Ausgaben" zählt aber das echte Buchungsdatum: beide
        // Buchungen müssen im Januar summiert erscheinen, Februar bleibt 0.
        $managementGroup->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $zinsen->id,
            'amount' => 141.35, 'period_date' => '2026-01-01', 'booking_date' => '2026-01-02',
        ]);
        $managementGroup->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $zinsen->id,
            'amount' => 141.76, 'period_date' => '2026-02-01', 'booking_date' => '2026-01-30',
        ]);

        $costs = $managementGroup->costs()->with('invoice.invoiceIssuer')->get();

        $prognostiziert = (new FinancialOverviewService)->expensesByCategory($costs, 2026);
        $gebucht = (new FinancialOverviewService)->expensesByCategory($costs, 2026, 'booking_date');

        // Prognostizierte Ausgaben: nach period_date, also eine Zeile in Jan und eine in Feb.
        $this->assertSame(141.35, $prognostiziert['categories'][0]['months'][1]);
        $this->assertSame(141.76, $prognostiziert['categories'][0]['months'][2]);

        // Gebuchte Ausgaben: nach booking_date, also beide Buchungen im Januar summiert,
        // Februar bleibt 0.
        $this->assertEqualsWithDelta(141.35 + 141.76, $gebucht['categories'][0]['months'][1], 0.001);
        $this->assertSame(0.0, $gebucht['categories'][0]['months'][2]);
    }

    public function test_expenses_by_category_breaks_the_monthly_amounts_down_by_rechnungssteller_and_account(): void
    {
        $this->seed(CostCategorySeeder::class);

        $managementUnit = $this->makeManagementUnit();
        $managementGroup = $managementUnit->managementGroup;
        $billingInterval = $managementGroup->billingIntervals()->first();
        $zinsen = CostCategory::where('name', 'Zinsen')->first();
        $kfwBank = InvoiceIssuer::create(['name' => 'KFW Bank', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $sparkasse = InvoiceIssuer::create(['name' => 'Sparkasse', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        $kfw = Invoice::create([
            'invoice_issuer_id' => $kfwBank->id, 'amount' => 100, 'invoice_date' => '2026-01-01',
            'periodicity' => 'monatlich', 'description' => 'KFW-Kredit',
        ]);
        $sparkassenKredit = Invoice::create([
            'invoice_issuer_id' => $sparkasse->id, 'amount' => 80, 'invoice_date' => '2026-03-01',
            'periodicity' => 'monatlich', 'description' => 'Sparkassen-Kredit',
        ]);

        $managementGroup->costs()->create([
            'invoice_id' => $kfw->id, 'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $zinsen->id,
            'amount' => 100, 'period_date' => '2026-01-15',
        ]);
        $managementGroup->costs()->create([
            'invoice_id' => $sparkassenKredit->id, 'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $zinsen->id,
            'amount' => 80, 'period_date' => '2026-03-15', 'booking_date' => '2026-03-15',
        ]);

        $result = (new FinancialOverviewService)->expensesByCategory($managementGroup->costs()->with('invoice.invoiceIssuer')->get(), 2026);
        $category = $result['categories'][0];

        // Kategoriezeile: Summe über beide Rechnungssteller je Monat.
        $this->assertSame(100.0, $category['months'][1]);
        $this->assertSame(80.0, $category['months'][3]);
        $this->assertSame(180.0, $category['total']);

        // Aufgeschlüsselt nach Rechnungssteller (+ Account, hier keiner gesetzt), alphabetisch
        // sortiert, jeweils mit eigener Monatsverteilung wie die Kategoriezeile selbst.
        $invoices = $category['invoices'];
        $this->assertCount(2, $invoices);
        $this->assertSame('KFW Bank', $invoices[0]['label']);
        $this->assertSame(100.0, $invoices[0]['months'][1]);
        $this->assertSame(100.0, $invoices[0]['total']);
        $this->assertSame('Sparkasse', $invoices[1]['label']);
        $this->assertSame(80.0, $invoices[1]['months'][3]);
        $this->assertSame(80.0, $invoices[1]['total']);
    }

    /**
     * The Nutzer's own scenario: two separate Rechnungen (e.g. "Rechnung-0" the recurring
     * one, "Rechnung-10" a one-off Nachzahlung) billed by the *same* Rechnungssteller under
     * the *same* Kundenaccount must appear as a single combined row, not one row each –
     * grouping is by Rechnungssteller + Account, not by individual Invoice.
     */
    public function test_expenses_by_category_combines_different_invoices_of_the_same_issuer_and_account_into_one_row(): void
    {
        $this->seed(CostCategorySeeder::class);

        $managementUnit = $this->makeManagementUnit();
        $managementGroup = $managementUnit->managementGroup;
        $billingInterval = $managementGroup->billingIntervals()->first();
        $zinsen = CostCategory::where('name', 'Zinsen')->first();
        $issuer = InvoiceIssuer::create(['name' => 'Beispielbank', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $account = $issuer->accounts()->create(['description' => 'KFW-Zinsen', 'customer_number' => '1']);

        $recurring = Invoice::create([
            'invoice_issuer_id' => $issuer->id, 'invoice_issuer_account_id' => $account->id, 'amount' => 100,
            'invoice_date' => '2026-02-01', 'periodicity' => 'monatlich', 'description' => 'Rechnung-0',
        ]);
        $oneOff = Invoice::create([
            'invoice_issuer_id' => $issuer->id, 'invoice_issuer_account_id' => $account->id, 'amount' => 50,
            'invoice_date' => '2026-01-01', 'periodicity' => 'einmalig', 'description' => 'Rechnung-10',
        ]);

        $managementGroup->costs()->create([
            'invoice_id' => $oneOff->id, 'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $zinsen->id,
            'amount' => 50, 'period_date' => '2026-01-31',
        ]);
        $managementGroup->costs()->create([
            'invoice_id' => $recurring->id, 'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $zinsen->id,
            'amount' => 100, 'period_date' => '2026-02-15',
        ]);

        $result = (new FinancialOverviewService)->expensesByCategory(
            $managementGroup->costs()->with('invoice.invoiceIssuer', 'invoice.invoiceIssuerAccount')->get(), 2026
        );
        $invoices = $result['categories'][0]['invoices'];

        $this->assertCount(1, $invoices);
        $this->assertSame('Beispielbank – KFW-Zinsen', $invoices[0]['label']);
        $this->assertSame(50.0, $invoices[0]['months'][1]);
        $this->assertSame(100.0, $invoices[0]['months'][2]);
        $this->assertSame(150.0, $invoices[0]['total']);
    }

    public function test_expenses_by_category_falls_back_to_a_generic_label_when_the_cost_has_no_invoice(): void
    {
        $this->seed(CostCategorySeeder::class);

        $managementUnit = $this->makeManagementUnit();
        $managementGroup = $managementUnit->managementGroup;
        $billingInterval = $managementGroup->billingIntervals()->first();
        $zinsen = CostCategory::where('name', 'Zinsen')->first();

        $managementGroup->costs()->create([
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $zinsen->id,
            'amount' => 50, 'period_date' => '2026-01-15',
        ]);

        $result = (new FinancialOverviewService)->expensesByCategory($managementGroup->costs, 2026);

        $this->assertSame('Ohne Rechnung', $result['categories'][0]['invoices'][0]['label']);
    }

    public function test_account_balances_debits_cost_allocations_in_the_month_of_their_period_date(): void
    {
        $this->seed(CostCategorySeeder::class);

        $managementUnit = $this->makeManagementUnit();
        $managementGroup = $managementUnit->managementGroup;
        $account = $managementGroup->accounts()->create(['name' => 'Girokonto', 'initial_balance' => 1000]);
        $category = CostCategory::first();
        $billingInterval = $managementGroup->billingIntervals()->first();

        $cost = $managementGroup->costs()->create([
            'billing_interval_id' => $billingInterval->id,
            'cost_category_id' => $category->id,
            'amount' => 150,
            'period_date' => '2026-03-15',
        ]);
        $cost->accounts()->attach($account->id, ['amount' => 150]);

        $result = (new FinancialOverviewService)->accountBalances($managementGroup, 2026);

        $row = $result['rows'][0];
        $this->assertSame(1000.0, $row['opening']);
        $this->assertSame(-150.0, $row['months'][3]);
        $this->assertSame(0.0, $row['months'][1]);
        $this->assertSame(850.0, $row['closing']);
    }

    public function test_account_balances_moves_money_between_accounts_via_transfers(): void
    {
        $managementUnit = $this->makeManagementUnit();
        $managementGroup = $managementUnit->managementGroup;
        $checking = $managementGroup->accounts()->create(['name' => 'Girokonto', 'initial_balance' => 1000]);
        $reserve = $managementGroup->accounts()->create(['name' => 'Rücklagenkonto', 'initial_balance' => 0]);

        AccountTransfer::create([
            'from_account_id' => $checking->id,
            'to_account_id' => $reserve->id,
            'amount' => 300,
            'transfer_date' => '2026-05-10',
        ]);

        $result = (new FinancialOverviewService)->accountBalances($managementGroup->fresh(), 2026);

        $rows = collect($result['rows'])->keyBy(fn ($row) => $row['account']->name);
        $this->assertSame(-300.0, $rows['Girokonto']['months'][5]);
        $this->assertSame(700.0, $rows['Girokonto']['closing']);
        $this->assertSame(300.0, $rows['Rücklagenkonto']['months'][5]);
        $this->assertSame(300.0, $rows['Rücklagenkonto']['closing']);
    }

    public function test_account_balances_credits_rent_payments_in_the_month_of_their_period_date(): void
    {
        $rentalUnit = $this->makeRentalUnit();
        $managementGroup = $rentalUnit->managementUnit->managementGroup;
        $account = $managementGroup->accounts()->create(['name' => 'Girokonto', 'initial_balance' => 0]);

        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create(['rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500]);
        $lease->tenants()->attach($tenant->id);

        $interval = $lease->currentLeaseInterval();
        $payment = $interval->rentPayments()->create([
            'period_date' => '2026-03-01', 'amount' => 333, 'booking_date' => '2026-03-03',
        ]);
        $payment->accounts()->attach($account->id, ['amount' => 333]);

        $result = (new FinancialOverviewService)->accountBalances($managementGroup, 2026);

        $row = $result['rows'][0];
        $this->assertSame(333.0, $row['months'][3]);
        $this->assertSame(0.0, $row['months'][2]);
        $this->assertSame(333.0, $row['closing']);
    }

    /**
     * "Übersicht aller Buchungen" (Konzept.md "### Betriebskostenabrechnung"): every
     * abrechnungsrelevante Cost of the given Abrechnungsintervall, grouped by
     * Kategorie, sorted by Kategoriename, with a per-category total and per-row
     * open/booked status. Eine nicht abrechnungsrelevante Kategorie (z. B. "Zinsen")
     * darf gar nicht erst auftauchen.
     */
    public function test_bookings_by_category_groups_costs_by_category_and_computes_totals(): void
    {
        $this->seed(CostCategorySeeder::class);
        $managementUnit = $this->makeManagementUnit();
        $billingInterval = $managementUnit->managementGroup->billingIntervals()->first();
        $heizkosten = CostCategory::where('name', 'Heizkosten')->first();
        $wasser = CostCategory::where('name', 'Wasserversorgung')->first();
        $zinsen = CostCategory::where('name', 'Zinsen')->first();
        $issuer = InvoiceIssuer::create(['name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'invoice_date' => $billingInterval->start_date,
            'amount' => 300,
            'periodicity' => 'once',
        ]);

        $managementUnit->costs()->create([
            'costable_type' => $managementUnit->getMorphClass(), 'costable_id' => $managementUnit->id,
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $heizkosten->id,
            'invoice_id' => $invoice->id, 'amount' => 100, 'period_date' => $billingInterval->start_date,
            'booking_date' => $billingInterval->start_date,
        ]);
        $managementUnit->costs()->create([
            'costable_type' => $managementUnit->getMorphClass(), 'costable_id' => $managementUnit->id,
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $heizkosten->id,
            'invoice_id' => $invoice->id, 'amount' => 50, 'period_date' => $billingInterval->start_date->copy()->addMonth(),
        ]);
        $managementUnit->costs()->create([
            'costable_type' => $managementUnit->getMorphClass(), 'costable_id' => $managementUnit->id,
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $wasser->id,
            'invoice_id' => $invoice->id, 'amount' => 30, 'period_date' => $billingInterval->start_date,
        ]);
        // Nicht abrechnungsrelevant (is_relevant_for_billing = false) – darf im Ergebnis
        // nicht auftauchen.
        $managementUnit->costs()->create([
            'costable_type' => $managementUnit->getMorphClass(), 'costable_id' => $managementUnit->id,
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $zinsen->id,
            'invoice_id' => $invoice->id, 'amount' => 999, 'period_date' => $billingInterval->start_date,
        ]);

        $costs = $managementUnit->costs()->with('costCategory', 'invoice.invoiceIssuer', 'invoice.invoiceIssuerAccount')->get();

        $result = (new FinancialOverviewService)->bookingsByCategory($costs, $billingInterval->id);
        $categories = $result['categories'];

        $this->assertCount(2, $categories);
        $this->assertSame('Heizkosten', $categories[0]['name']);
        $this->assertSame(150.0, $categories[0]['total']);
        $this->assertCount(2, $categories[0]['rows']);
        $this->assertFalse($categories[0]['rows'][0]['open']);
        $this->assertSame('Stadtwerke', $categories[0]['rows'][0]['label']);
        $this->assertTrue($categories[0]['rows'][1]['open']);
        $this->assertSame('Wasserversorgung', $categories[1]['name']);
        $this->assertSame(30.0, $categories[1]['total']);
        $this->assertFalse(collect($categories)->contains(fn ($c) => $c['name'] === 'Zinsen'));
        // Gesamtsumme über alle abrechnungsrelevanten Kategorien (150 + 30), nicht die
        // ausgeschlossenen 999 der nicht abrechnungsrelevanten "Zinsen".
        $this->assertSame(180.0, $result['grandTotal']);
    }

    /**
     * Nutzerfeedback (nachdem eine falsch verknüpfte Rechnung im Konto-Label nicht von
     * einer anderen zu unterscheiden war): die Beschreibung der Rechnung wird pro
     * Buchung mit angezeigt, nicht nur Rechnungssteller/Konto-Label.
     */
    public function test_bookings_by_category_includes_the_invoices_description(): void
    {
        $this->seed(CostCategorySeeder::class);
        $managementUnit = $this->makeManagementUnit();
        $billingInterval = $managementUnit->managementGroup->billingIntervals()->first();
        $heizkosten = CostCategory::where('name', 'Heizkosten')->first();
        $issuer = InvoiceIssuer::create(['name' => 'Stadtwerke', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $invoice = Invoice::create([
            'invoice_issuer_id' => $issuer->id,
            'costable_type' => $managementUnit->getMorphClass(),
            'costable_id' => $managementUnit->id,
            'invoice_date' => $billingInterval->start_date,
            'amount' => 100,
            'description' => 'Fernwärme 2024',
            'periodicity' => 'once',
        ]);
        $managementUnit->costs()->create([
            'costable_type' => $managementUnit->getMorphClass(), 'costable_id' => $managementUnit->id,
            'billing_interval_id' => $billingInterval->id, 'cost_category_id' => $heizkosten->id,
            'invoice_id' => $invoice->id, 'amount' => 100, 'period_date' => $billingInterval->start_date,
            'booking_date' => $billingInterval->start_date,
        ]);

        $costs = $managementUnit->costs()->with('costCategory', 'invoice.invoiceIssuer', 'invoice.invoiceIssuerAccount')->get();
        $result = (new FinancialOverviewService)->bookingsByCategory($costs, $billingInterval->id);

        $this->assertSame('Fernwärme 2024', $result['categories'][0]['rows'][0]['description']);
    }
}
