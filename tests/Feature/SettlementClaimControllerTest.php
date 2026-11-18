<?php

namespace Tests\Feature;

use App\Models\BillingInterval;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\RentalUnit;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the create/edit forms, authorization, and CRUD for Forderungen
 * (SettlementClaim) on a Lease, sowie – Konzept.md "#### Berechnung": "Zu diesem
 * Leerstand müssen auch Forderungen, die z. B. von einer Abrechnungsgesellschaft
 * kommen, verbucht werden." – auf einer leerstehenden Mieteinheit.
 */
class SettlementClaimControllerTest extends TestCase
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
        $tenant = Tenant::create(['user_group_id' => $group->id, 'first_name' => 'Max', 'last_name' => 'Mustermann', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);

        return $lease;
    }

    private function makeVacantRentalUnit(User $user): RentalUnit
    {
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);

        return $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true,
        ]);
    }

    private function makeBillingInterval(Lease $lease): BillingInterval
    {
        $managementGroup = $lease->rentalUnit->managementUnit->managementGroup;

        return $managementGroup->billingIntervals()->create([
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'description' => 'Jahr 2026',
        ]);
    }

    private function makeBillingIntervalForRentalUnit(RentalUnit $rentalUnit): BillingInterval
    {
        $managementGroup = $rentalUnit->managementUnit->managementGroup;

        return $managementGroup->billingIntervals()->create([
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'description' => 'Jahr 2026',
        ]);
    }

    public function test_the_create_form_can_be_rendered_for_a_visible_lease(): void
    {
        $user = User::factory()->create();
        $lease = $this->makeLease($user);

        $response = $this->actingAs($user)->get(route('settlement-claims.create', ['lease_id' => $lease->id]));

        $response->assertOk();
    }

    public function test_the_create_form_is_forbidden_for_an_outsider(): void
    {
        $owner = User::factory()->create();
        $lease = $this->makeLease($owner);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->get(route('settlement-claims.create', ['lease_id' => $lease->id]));

        $response->assertForbidden();
    }

    public function test_a_claim_can_be_created(): void
    {
        $user = User::factory()->create();
        $lease = $this->makeLease($user);
        $billingInterval = $this->makeBillingInterval($lease);

        $response = $this->actingAs($user)->post(route('settlement-claims.store'), [
            'lease_id' => $lease->id,
            'billing_interval_id' => $billingInterval->id,
            'date' => '2026-06-01',
            'amount' => 123.45,
            'notes' => 'Nachzahlung Betriebskosten',
        ]);

        $response->assertRedirect(route('leases.show', $lease).'#forderungen');
        $this->assertSame(1, $lease->settlementClaims()->count());
        $claim = $lease->settlementClaims()->first();
        $this->assertEquals(123.45, $claim->amount);
        $this->assertNull($claim->booking_date);
    }

    public function test_storing_a_claim_is_forbidden_for_an_outsider(): void
    {
        $owner = User::factory()->create();
        $lease = $this->makeLease($owner);
        $billingInterval = $this->makeBillingInterval($lease);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->post(route('settlement-claims.store'), [
            'lease_id' => $lease->id,
            'billing_interval_id' => $billingInterval->id,
            'date' => '2026-06-01',
            'amount' => 123.45,
        ]);

        $response->assertForbidden();
        $this->assertSame(0, $lease->settlementClaims()->count());
    }

    public function test_a_claims_billing_interval_must_belong_to_the_same_management_group(): void
    {
        $user = User::factory()->create();
        $lease = $this->makeLease($user);
        $otherLease = $this->makeLease($user);
        $foreignInterval = $this->makeBillingInterval($otherLease);

        $response = $this->actingAs($user)->post(route('settlement-claims.store'), [
            'lease_id' => $lease->id,
            'billing_interval_id' => $foreignInterval->id,
            'date' => '2026-06-01',
            'amount' => 100,
        ]);

        $response->assertSessionHasErrors('billing_interval_id');
        $this->assertSame(0, $lease->settlementClaims()->count());
    }

    public function test_the_edit_form_can_be_rendered_for_a_visible_claim(): void
    {
        $user = User::factory()->create();
        $lease = $this->makeLease($user);
        $billingInterval = $this->makeBillingInterval($lease);
        $claim = $lease->settlementClaims()->create([
            'billing_interval_id' => $billingInterval->id, 'date' => '2026-06-01', 'amount' => 50,
        ]);

        $response = $this->actingAs($user)->get(route('settlement-claims.edit', $claim));

        $response->assertOk();
    }

    public function test_the_edit_form_is_forbidden_for_an_outsider(): void
    {
        $owner = User::factory()->create();
        $lease = $this->makeLease($owner);
        $billingInterval = $this->makeBillingInterval($lease);
        $claim = $lease->settlementClaims()->create([
            'billing_interval_id' => $billingInterval->id, 'date' => '2026-06-01', 'amount' => 50,
        ]);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->get(route('settlement-claims.edit', $claim));

        $response->assertForbidden();
    }

    public function test_a_claim_can_be_updated(): void
    {
        $user = User::factory()->create();
        $lease = $this->makeLease($user);
        $billingInterval = $this->makeBillingInterval($lease);
        $claim = $lease->settlementClaims()->create([
            'billing_interval_id' => $billingInterval->id, 'date' => '2026-06-01', 'amount' => 50,
        ]);

        $response = $this->actingAs($user)->put(route('settlement-claims.update', $claim), [
            'billing_interval_id' => $billingInterval->id,
            'date' => '2026-06-15',
            'amount' => -75.50,
            'booking_date' => '2026-06-20',
            'notes' => 'Rückzahlung',
        ]);

        $response->assertRedirect(route('leases.show', $lease).'#forderungen');
        $claim->refresh();
        $this->assertEquals(-75.50, $claim->amount);
        $this->assertSame('2026-06-20', $claim->booking_date->toDateString());
        $this->assertSame('Rückzahlung', $claim->notes);
    }

    public function test_updating_a_claim_is_forbidden_for_an_outsider(): void
    {
        $owner = User::factory()->create();
        $lease = $this->makeLease($owner);
        $billingInterval = $this->makeBillingInterval($lease);
        $claim = $lease->settlementClaims()->create([
            'billing_interval_id' => $billingInterval->id, 'date' => '2026-06-01', 'amount' => 50,
        ]);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->put(route('settlement-claims.update', $claim), [
            'billing_interval_id' => $billingInterval->id,
            'date' => '2026-06-01',
            'amount' => 999,
        ]);

        $response->assertForbidden();
        $this->assertEquals(50, $claim->fresh()->amount);
    }

    public function test_a_claim_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $lease = $this->makeLease($user);
        $billingInterval = $this->makeBillingInterval($lease);
        $claim = $lease->settlementClaims()->create([
            'billing_interval_id' => $billingInterval->id, 'date' => '2026-06-01', 'amount' => 50,
        ]);

        $response = $this->actingAs($user)->delete(route('settlement-claims.destroy', $claim));

        $response->assertRedirect(route('leases.show', $lease).'#forderungen');
        $this->assertSame(0, $lease->settlementClaims()->count());
    }

    public function test_deleting_a_claim_is_forbidden_for_an_outsider(): void
    {
        $owner = User::factory()->create();
        $lease = $this->makeLease($owner);
        $billingInterval = $this->makeBillingInterval($lease);
        $claim = $lease->settlementClaims()->create([
            'billing_interval_id' => $billingInterval->id, 'date' => '2026-06-01', 'amount' => 50,
        ]);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->delete(route('settlement-claims.destroy', $claim));

        $response->assertForbidden();
        $this->assertSame(1, $lease->settlementClaims()->count());
    }

    public function test_the_create_form_can_be_rendered_for_a_visible_vacant_rental_unit(): void
    {
        $user = User::factory()->create();
        $rentalUnit = $this->makeVacantRentalUnit($user);

        $response = $this->actingAs($user)->get(route('settlement-claims.create', ['rental_unit_id' => $rentalUnit->id]));

        $response->assertOk();
        $response->assertSee(__('Leerstand'));
    }

    public function test_the_create_form_is_forbidden_for_an_outsider_on_a_vacant_rental_unit(): void
    {
        $owner = User::factory()->create();
        $rentalUnit = $this->makeVacantRentalUnit($owner);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->get(route('settlement-claims.create', ['rental_unit_id' => $rentalUnit->id]));

        $response->assertForbidden();
    }

    public function test_neither_lease_id_nor_rental_unit_id_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('settlement-claims.create'));

        $response->assertSessionHasErrors('lease_id');
    }

    public function test_both_lease_id_and_rental_unit_id_is_rejected(): void
    {
        $user = User::factory()->create();
        $lease = $this->makeLease($user);
        $rentalUnit = $this->makeVacantRentalUnit($user);

        $response = $this->actingAs($user)->get(route('settlement-claims.create', [
            'lease_id' => $lease->id, 'rental_unit_id' => $rentalUnit->id,
        ]));

        $response->assertSessionHasErrors('lease_id');
    }

    public function test_a_vacancy_claim_can_be_created(): void
    {
        $user = User::factory()->create();
        $rentalUnit = $this->makeVacantRentalUnit($user);
        $billingInterval = $this->makeBillingIntervalForRentalUnit($rentalUnit);

        $response = $this->actingAs($user)->post(route('settlement-claims.store'), [
            'rental_unit_id' => $rentalUnit->id,
            'billing_interval_id' => $billingInterval->id,
            'date' => '2026-06-01',
            'amount' => 77.00,
            'notes' => 'Forderung Abrechnungsgesellschaft',
        ]);

        $response->assertRedirect(route('management-units.show', $rentalUnit->managementUnit).'#leerstand');
        $this->assertSame(1, $rentalUnit->settlementClaims()->count());
        $claim = $rentalUnit->settlementClaims()->first();
        $this->assertNull($claim->lease_id);
        $this->assertTrue($claim->isForVacancy());
        $this->assertEquals(77.00, $claim->amount);
    }

    public function test_storing_a_vacancy_claim_is_forbidden_for_an_outsider(): void
    {
        $owner = User::factory()->create();
        $rentalUnit = $this->makeVacantRentalUnit($owner);
        $billingInterval = $this->makeBillingIntervalForRentalUnit($rentalUnit);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->post(route('settlement-claims.store'), [
            'rental_unit_id' => $rentalUnit->id,
            'billing_interval_id' => $billingInterval->id,
            'date' => '2026-06-01',
            'amount' => 77.00,
        ]);

        $response->assertForbidden();
        $this->assertSame(0, $rentalUnit->settlementClaims()->count());
    }

    public function test_the_edit_form_can_be_rendered_for_a_visible_vacancy_claim(): void
    {
        $user = User::factory()->create();
        $rentalUnit = $this->makeVacantRentalUnit($user);
        $billingInterval = $this->makeBillingIntervalForRentalUnit($rentalUnit);
        $claim = $rentalUnit->settlementClaims()->create([
            'billing_interval_id' => $billingInterval->id, 'date' => '2026-06-01', 'amount' => 50,
        ]);

        $response = $this->actingAs($user)->get(route('settlement-claims.edit', $claim));

        $response->assertOk();
    }

    public function test_a_vacancy_claim_can_be_updated(): void
    {
        $user = User::factory()->create();
        $rentalUnit = $this->makeVacantRentalUnit($user);
        $billingInterval = $this->makeBillingIntervalForRentalUnit($rentalUnit);
        $claim = $rentalUnit->settlementClaims()->create([
            'billing_interval_id' => $billingInterval->id, 'date' => '2026-06-01', 'amount' => 50,
        ]);

        $response = $this->actingAs($user)->put(route('settlement-claims.update', $claim), [
            'billing_interval_id' => $billingInterval->id,
            'date' => '2026-06-15',
            'amount' => 90,
            'booking_date' => '2026-06-20',
        ]);

        $response->assertRedirect(route('management-units.show', $rentalUnit->managementUnit).'#leerstand');
        $claim->refresh();
        $this->assertEquals(90, $claim->amount);
    }

    public function test_updating_a_vacancy_claim_is_forbidden_for_an_outsider(): void
    {
        $owner = User::factory()->create();
        $rentalUnit = $this->makeVacantRentalUnit($owner);
        $billingInterval = $this->makeBillingIntervalForRentalUnit($rentalUnit);
        $claim = $rentalUnit->settlementClaims()->create([
            'billing_interval_id' => $billingInterval->id, 'date' => '2026-06-01', 'amount' => 50,
        ]);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->put(route('settlement-claims.update', $claim), [
            'billing_interval_id' => $billingInterval->id,
            'date' => '2026-06-01',
            'amount' => 999,
        ]);

        $response->assertForbidden();
        $this->assertEquals(50, $claim->fresh()->amount);
    }

    public function test_a_vacancy_claim_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $rentalUnit = $this->makeVacantRentalUnit($user);
        $billingInterval = $this->makeBillingIntervalForRentalUnit($rentalUnit);
        $claim = $rentalUnit->settlementClaims()->create([
            'billing_interval_id' => $billingInterval->id, 'date' => '2026-06-01', 'amount' => 50,
        ]);

        $response = $this->actingAs($user)->delete(route('settlement-claims.destroy', $claim));

        $response->assertRedirect(route('management-units.show', $rentalUnit->managementUnit).'#leerstand');
        $this->assertSame(0, $rentalUnit->settlementClaims()->count());
    }
}
