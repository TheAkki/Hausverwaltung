<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\RentalUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingStatementLeaseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the "Vorvertrag" (Lease::is_pre_contract): a pre-contract is not billed on its
 * own – the following lease of the same Mieteinheit absorbs its period and
 * Betriebskostenvorauszahlung – plus the "not the last lease" validation and the UI.
 */
class LeaseVorvertragTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: RentalUnit, 2: Tenant}
     */
    private function makeRentalUnit(): array
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create(['street' => 'S', 'postal_code' => '1', 'city' => 'C']);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'size' => 60, 'is_relevant_for_billing' => true]);

        $tenant = Tenant::create([
            'first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B',
            'user_group_id' => $group->id,
        ]);

        return [$user, $rentalUnit, $tenant];
    }

    private function makeLease(RentalUnit $rentalUnit, Tenant $tenant, string $start, ?string $end, bool $isPreContract, float $utilityAdvance): Lease
    {
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id,
            'start_date' => $start,
            'end_date' => $end,
            'is_pre_contract' => $isPreContract,
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);
        $lease->leaseIntervals()->update(['utility_advance_payment' => $utilityAdvance]);

        return $lease;
    }

    public function test_a_pre_contract_is_not_a_statement_of_its_own_and_is_folded_into_the_following_lease(): void
    {
        [, $rentalUnit, $tenant] = $this->makeRentalUnit();
        $managementUnit = $rentalUnit->managementUnit;
        $managementGroup = $managementUnit->managementGroup;

        $pre = $this->makeLease($rentalUnit, $tenant, '2026-01-01', '2026-01-31', isPreContract: true, utilityAdvance: 100);
        $main = $this->makeLease($rentalUnit, $tenant, '2026-02-01', '2026-12-31', isPreContract: false, utilityAdvance: 200);

        $billingInterval = $managementGroup->billingIntervals()->first();
        $billingInterval->update(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $entries = (new BillingStatementLeaseResolver)->forInterval($managementUnit->fresh(), $billingInterval->fresh());

        // Only the following lease produces an entry; the Vorvertrag itself does not.
        $this->assertCount(1, $entries);
        $this->assertTrue($entries->first()['lease']->is($main));

        // Its period is extended back over the pre-contract (start 1.1. not 1.2.).
        $this->assertSame('2026-01-01', $entries->first()['periodStart']->toDateString());

        // Januar zählt die Vorauszahlung des Vorvertrags (100), Feb–Dez die des Hauptvertrags
        // (11 × 200) => 100 + 2200 = 2300.
        $this->assertEqualsWithDelta(2300.0, $entries->first()['totalUtilityAdvance'], 0.01);

        unset($pre);
    }

    public function test_the_store_form_rejects_a_pre_contract_that_is_the_last_lease(): void
    {
        [$user, $rentalUnit, $tenant] = $this->makeRentalUnit();

        $response = $this->actingAs($user)->post(route('leases.store'), [
            'rental_unit_id' => $rentalUnit->id,
            'tenant_ids' => [$tenant->id],
            'start_date' => '2026-01-01',
            'is_pre_contract' => '1',
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 500,
        ]);

        $response->assertSessionHasErrors('is_pre_contract');
        $this->assertSame(0, Lease::count());
    }

    public function test_the_store_form_accepts_a_pre_contract_when_a_following_lease_exists(): void
    {
        [$user, $rentalUnit, $tenant] = $this->makeRentalUnit();
        $this->makeLease($rentalUnit, $tenant, '2026-02-01', null, isPreContract: false, utilityAdvance: 200);

        $response = $this->actingAs($user)->post(route('leases.store'), [
            'rental_unit_id' => $rentalUnit->id,
            'tenant_ids' => [$tenant->id],
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'is_pre_contract' => '1',
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 500,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertTrue(Lease::whereDate('start_date', '2026-01-01')->first()->is_pre_contract);
    }

    public function test_the_update_form_can_toggle_the_pre_contract_flag_off(): void
    {
        [$user, $rentalUnit, $tenant] = $this->makeRentalUnit();
        $this->makeLease($rentalUnit, $tenant, '2026-02-01', null, isPreContract: false, utilityAdvance: 200);
        $pre = $this->makeLease($rentalUnit, $tenant, '2026-01-01', '2026-01-31', isPreContract: true, utilityAdvance: 100);

        $this->actingAs($user)->put(route('leases.update', $pre), [
            'tenant_ids' => [$tenant->id],
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'is_pre_contract' => '0',
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 500,
        ])->assertSessionHasNoErrors();

        $this->assertFalse($pre->fresh()->is_pre_contract);
    }

    public function test_the_pre_contract_flag_is_shown_on_the_lease_detail_page(): void
    {
        [$user, $rentalUnit, $tenant] = $this->makeRentalUnit();
        $this->makeLease($rentalUnit, $tenant, '2026-02-01', null, isPreContract: false, utilityAdvance: 200);
        $pre = $this->makeLease($rentalUnit, $tenant, '2026-01-01', '2026-01-31', isPreContract: true, utilityAdvance: 100);

        $this->actingAs($user)->get(route('leases.show', $pre))
            ->assertOk()
            ->assertSee('Vorvertrag');
    }

    public function test_a_pre_contract_is_shown_in_the_overview_with_a_badge_and_a_link_to_its_successor_when_expired_leases_are_included(): void
    {
        [$user, $rentalUnit, $tenant] = $this->makeRentalUnit();
        $main = $this->makeLease($rentalUnit, $tenant, '2026-02-01', null, isPreContract: false, utilityAdvance: 200);
        $this->makeLease($rentalUnit, $tenant, '2026-01-01', '2026-01-31', isPreContract: true, utilityAdvance: 100);

        // Like any other lease with a past end_date, a Vorvertrag is hidden by default...
        $this->actingAs($user)->get(route('leases.index'))
            ->assertOk()
            ->assertDontSee('Vorvertrag');

        // ...but appears, clearly marked and linked to its successor, once expired leases
        // are shown.
        $this->actingAs($user)->get(route('leases.index', ['show_expired' => 1]))
            ->assertOk()
            ->assertSee('Vorvertrag')
            ->assertSee(route('leases.show', $main));
    }
}
