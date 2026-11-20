<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomeTableSummeColumnTest extends TestCase
{
    use RefreshDatabase;

    private function makeLease(User $user): Lease
    {
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $rentalUnit = $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true,
        ]);
        $tenant = Tenant::create(['first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);

        return $lease;
    }

    public function test_the_tenant_unit_income_table_shows_a_row_total_and_grand_total(): void
    {
        $user = User::factory()->create();
        $this->makeLease($user);
        $managementUnit = ManagementUnit::first();

        $response = $this->actingAs($user)->get(route('management-units.show', $managementUnit).'?income_view=tenant_unit');

        $response->assertOk();
        // 500 €/month * 12 months = 6000 € row total and grand total.
        $response->assertSeeInOrder(['Mustermann', euro(6000).' €']);
    }

    public function test_the_tenant_income_table_shows_a_row_total(): void
    {
        $user = User::factory()->create();
        $this->makeLease($user);
        $managementUnit = ManagementUnit::first();

        $response = $this->actingAs($user)->get(route('management-units.show', $managementUnit).'?income_view=tenant');

        $response->assertOk();
        $response->assertSeeInOrder(['Mustermann', euro(6000).' €']);
    }

    /**
     * The "tenant_split" view's Summe column used to combine a tenant's Miete and
     * Nebenkosten into one number spanning both their rows. Now each row (Miete/
     * Nebenkosten) shows its own yearly total instead, matching what that row's own
     * months already display.
     */
    public function test_the_tenant_split_income_table_shows_separate_row_totals_for_miete_and_nebenkosten(): void
    {
        $user = User::factory()->create();
        $lease = $this->makeLease($user);
        $lease->currentLeaseInterval()->update(['utility_advance_payment' => 100]);
        $managementUnit = ManagementUnit::first();

        $response = $this->actingAs($user)->get(route('management-units.show', $managementUnit).'?income_view=tenant_split');

        $response->assertOk();
        // Miete: 500 €/month * 12 = 6000 €. Nebenkosten: 100 €/month * 12 = 1200 €.
        $response->assertSeeInOrder(['Mustermann', 'Miete', euro(6000).' €']);
        $response->assertSeeInOrder(['Nebenkosten', euro(1200).' €']);
    }

    /**
     * The "tenant_split" view's footer used to collapse Miete/Nebenkosten back into one
     * combined "Summe" number, discarding the split every row above it already shows.
     * Now it has its own "Miete"/"Nebenkosten" sub-rows, summed across every relevant
     * Mietvertrag (here: two different Mieteinheiten of the same Verwaltungseinheit).
     */
    public function test_the_tenant_split_income_table_shows_separate_summe_rows_for_miete_and_nebenkosten(): void
    {
        $user = User::factory()->create();
        $lease1 = $this->makeLease($user);
        $lease1->currentLeaseInterval()->update(['utility_advance_payment' => 100]);

        $managementUnit = ManagementUnit::first();
        $rentalUnit2 = $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 40, 'is_relevant_for_billing' => true,
        ]);
        $tenant2 = Tenant::create(['first_name' => 'Erika', 'last_name' => 'Musterfrau', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease2 = Lease::create([
            'rental_unit_id' => $rentalUnit2->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 300,
        ]);
        $lease2->tenants()->attach($tenant2->id);
        $lease2->currentLeaseInterval()->update(['utility_advance_payment' => 50]);

        $response = $this->actingAs($user)->get(route('management-units.show', $managementUnit).'?income_view=tenant_split');

        $response->assertOk();
        // Miete: (500 + 300) €/month * 12 = 9600 € across both Mieteinheiten.
        // Nebenkosten: (100 + 50) €/month * 12 = 1800 € across both Mieteinheiten.
        $response->assertSeeInOrder(['Summe', 'Miete', euro(9600).' €']);
        $response->assertSeeInOrder(['Nebenkosten', euro(1800).' €']);
    }
}
