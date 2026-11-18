<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\RentalUnit;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers CRUD for Mietverträge (leases): the context-dependent create dialog
 * (rental_unit/management_unit/global scope), overlap validation, and cross-group
 * tenant/rental-unit authorization.
 */
class LeaseControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeRentalUnit(User $user): RentalUnit
    {
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create(['street' => 'A', 'postal_code' => '1', 'city' => 'B']);

        return $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'is_relevant_for_billing' => true]);
    }

    private function makeTenant(int $userGroupId): Tenant
    {
        return Tenant::create([
            'user_group_id' => $userGroupId,
            'first_name' => 'Max', 'last_name' => 'Mustermann',
            'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ]);
    }

    public function test_the_create_form_scopes_to_a_given_rental_unit(): void
    {
        $user = User::factory()->create();
        $rentalUnit = $this->makeRentalUnit($user);

        $response = $this->actingAs($user)->get(route('leases.create', ['rental_unit_id' => $rentalUnit->id]));

        $response->assertOk();
        $this->assertSame('rental_unit', $response->viewData('scope'));
    }

    public function test_the_create_form_offers_referenceable_leases_for_a_non_abrechnungsrelevante_rental_unit(): void
    {
        $user = User::factory()->create();
        $wohnung = $this->makeRentalUnit($user);
        $wohnungLease = $wohnung->leases()->create([
            'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $garage = $wohnung->managementUnit->rentalUnits()->create(['type' => 'Garage', 'is_relevant_for_billing' => false]);

        $response = $this->actingAs($user)->get(route('leases.create', ['rental_unit_id' => $garage->id]));

        $response->assertOk();
        $this->assertTrue($response->viewData('referenceableLeases')->contains('id', $wohnungLease->id));
    }

    public function test_the_create_form_offers_no_referenceable_leases_for_an_abrechnungsrelevante_rental_unit(): void
    {
        $user = User::factory()->create();
        $rentalUnit = $this->makeRentalUnit($user);

        $response = $this->actingAs($user)->get(route('leases.create', ['rental_unit_id' => $rentalUnit->id]));

        $response->assertOk();
        $this->assertTrue($response->viewData('referenceableLeases')->isEmpty());
    }

    public function test_the_create_form_is_forbidden_for_a_rental_unit_of_another_group(): void
    {
        $owner = User::factory()->create();
        $rentalUnit = $this->makeRentalUnit($owner);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->get(route('leases.create', ['rental_unit_id' => $rentalUnit->id]));

        $response->assertForbidden();
    }

    public function test_the_create_form_scopes_to_a_given_management_unit(): void
    {
        $user = User::factory()->create();
        $rentalUnit = $this->makeRentalUnit($user);
        $managementUnit = $rentalUnit->managementUnit;

        $response = $this->actingAs($user)->get(route('leases.create', ['management_unit_id' => $managementUnit->id]));

        $response->assertOk();
        $this->assertSame('management_unit', $response->viewData('scope'));
    }

    public function test_the_create_form_is_forbidden_for_a_management_unit_of_another_group(): void
    {
        $owner = User::factory()->create();
        $rentalUnit = $this->makeRentalUnit($owner);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->get(route('leases.create', ['management_unit_id' => $rentalUnit->managementUnit->id]));

        $response->assertForbidden();
    }

    public function test_the_create_form_defaults_to_the_global_scope(): void
    {
        $user = User::factory()->create();
        $this->makeRentalUnit($user);

        $response = $this->actingAs($user)->get(route('leases.create'));

        $response->assertOk();
        $this->assertSame('global', $response->viewData('scope'));
    }

    /**
     * Konzept.md "### Detailbild Mieteinheit": the Mietvertrag-Kopplungs-Option must
     * not be limited to the dialog opened directly from a Mieteinheit – the
     * "management_unit"/"global" scopes only pick the Mieteinheit via a <select>, so
     * the candidate list is sent along per Mieteinheit for the view's JavaScript to
     * pick from once a Mieteinheit is actually selected.
     */
    public function test_the_create_form_offers_referenceable_leases_by_rental_unit_for_the_management_unit_scope(): void
    {
        $user = User::factory()->create();
        $wohnung = $this->makeRentalUnit($user);
        $wohnungLease = $wohnung->leases()->create([
            'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $garage = $wohnung->managementUnit->rentalUnits()->create(['type' => 'Garage', 'is_relevant_for_billing' => false]);

        $response = $this->actingAs($user)->get(route('leases.create', ['management_unit_id' => $wohnung->managementUnit->id]));

        $response->assertOk();
        $byRentalUnit = $response->viewData('referenceableLeasesByRentalUnit');
        $this->assertArrayHasKey($garage->id, $byRentalUnit);
        $this->assertSame($wohnungLease->id, $byRentalUnit[$garage->id][0]['id']);
        $this->assertArrayNotHasKey($wohnung->id, $byRentalUnit);
    }

    public function test_the_create_form_offers_referenceable_leases_by_rental_unit_for_the_global_scope(): void
    {
        $user = User::factory()->create();
        $wohnung = $this->makeRentalUnit($user);
        $wohnungLease = $wohnung->leases()->create([
            'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $garage = $wohnung->managementUnit->rentalUnits()->create(['type' => 'Garage', 'is_relevant_for_billing' => false]);

        $response = $this->actingAs($user)->get(route('leases.create'));

        $response->assertOk();
        $byRentalUnit = $response->viewData('referenceableLeasesByRentalUnit');
        $this->assertArrayHasKey($garage->id, $byRentalUnit);
        $this->assertSame($wohnungLease->id, $byRentalUnit[$garage->id][0]['id']);
    }

    public function test_a_lease_can_be_coupled_to_another_leases_reference_via_the_global_scope(): void
    {
        $user = User::factory()->create();
        $wohnung = $this->makeRentalUnit($user);
        $wohnungLease = $wohnung->leases()->create([
            'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $tenant = $this->makeTenant($user->groups()->first()->id);
        $wohnungLease->tenants()->attach($tenant->id);
        $garage = $wohnung->managementUnit->rentalUnits()->create(['type' => 'Garage', 'is_relevant_for_billing' => false]);

        $response = $this->actingAs($user)->post(route('leases.store'), [
            'rental_unit_id' => $garage->id,
            'assignment_mode' => 'reference',
            'coupled_to_lease_id' => $wohnungLease->id,
            'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 30,
        ]);

        $garageLease = $garage->leases()->first();
        $this->assertNotNull($garageLease);
        $response->assertRedirect(route('leases.show', $garageLease));
        $this->assertSame($wohnungLease->id, $garageLease->coupled_to_lease_id);
        $this->assertTrue($garageLease->tenants->contains($tenant->id));
    }

    public function test_a_lease_can_be_created(): void
    {
        $user = User::factory()->create();
        $rentalUnit = $this->makeRentalUnit($user);
        $tenant = $this->makeTenant($user->groups()->first()->id);

        $response = $this->actingAs($user)->post(route('leases.store'), [
            'rental_unit_id' => $rentalUnit->id,
            'tenant_ids' => [$tenant->id],
            'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 500,
        ]);

        $lease = $rentalUnit->leases()->first();
        $this->assertNotNull($lease);
        $response->assertRedirect(route('leases.show', $lease));
        $this->assertTrue($lease->tenants->contains($tenant->id));
    }

    public function test_a_lease_cannot_be_created_for_a_rental_unit_of_another_group(): void
    {
        $owner = User::factory()->create();
        $rentalUnit = $this->makeRentalUnit($owner);

        $user = User::factory()->create();
        $tenant = $this->makeTenant($user->groups()->first()->id);

        $response = $this->actingAs($user)->post(route('leases.store'), [
            'rental_unit_id' => $rentalUnit->id,
            'tenant_ids' => [$tenant->id],
            'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 500,
        ]);

        $response->assertForbidden();
        $this->assertSame(0, $rentalUnit->leases()->count());
    }

    public function test_a_lease_cannot_reference_a_tenant_of_another_group(): void
    {
        $user = User::factory()->create();
        $rentalUnit = $this->makeRentalUnit($user);

        $outsider = User::factory()->create();
        $foreignTenant = $this->makeTenant($outsider->groups()->first()->id);

        $response = $this->actingAs($user)->post(route('leases.store'), [
            'rental_unit_id' => $rentalUnit->id,
            'tenant_ids' => [$foreignTenant->id],
            'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 500,
        ]);

        $response->assertSessionHasErrors('tenant_ids.0');
        $this->assertSame(0, $rentalUnit->leases()->count());
    }

    public function test_an_overlapping_lease_is_rejected(): void
    {
        $user = User::factory()->create();
        $rentalUnit = $this->makeRentalUnit($user);
        $tenant = $this->makeTenant($user->groups()->first()->id);
        $rentalUnit->leases()->create([
            'start_date' => '2026-01-01', 'end_date' => '2026-06-30',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);

        $response = $this->actingAs($user)->post(route('leases.store'), [
            'rental_unit_id' => $rentalUnit->id,
            'tenant_ids' => [$tenant->id],
            'start_date' => '2026-03-01',
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 500,
        ]);

        $response->assertSessionHasErrors('start_date');
        $this->assertSame(1, $rentalUnit->leases()->count());
    }

    /**
     * Konzept.md "### Detailbild Mieteinheit": a nicht abrechnungsrelevante Mieteinheit
     * (e.g. eine Garage) can couple its new Mietvertrag to an existing Mietvertrag of an
     * abrechnungsrelevanten Mieteinheit of the same Verwaltungseinheit, inheriting its
     * Mieter instead of assigning its own.
     */
    public function test_a_lease_can_be_coupled_to_another_leases_reference(): void
    {
        $user = User::factory()->create();
        $wohnung = $this->makeRentalUnit($user);
        $tenant = $this->makeTenant($user->groups()->first()->id);
        $wohnungLease = $wohnung->leases()->create([
            'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $wohnungLease->tenants()->attach($tenant->id);

        $garage = $wohnung->managementUnit->rentalUnits()->create(['type' => 'Garage', 'is_relevant_for_billing' => false]);

        $response = $this->actingAs($user)->post(route('leases.store'), [
            'rental_unit_id' => $garage->id,
            'assignment_mode' => 'reference',
            'coupled_to_lease_id' => $wohnungLease->id,
            'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 30,
        ]);

        $garageLease = $garage->leases()->first();
        $this->assertNotNull($garageLease);
        $response->assertRedirect(route('leases.show', $garageLease));
        $this->assertSame($wohnungLease->id, $garageLease->coupled_to_lease_id);
        $this->assertTrue($garageLease->tenants->contains($tenant->id));

        // The coupled lease's own show page must render fine and note the coupling,
        // without offering to book its Mietzahlungen directly (Konzept.md "### Mietvertrag").
        $showResponse = $this->actingAs($user)->get(route('leases.show', $garageLease));
        $showResponse->assertOk();
        $showResponse->assertSee($wohnungLease->tenantNames());
    }

    public function test_coupling_a_lease_of_an_abrechnungsrelevante_mieteinheit_to_a_reference_is_rejected(): void
    {
        $user = User::factory()->create();
        $wohnung = $this->makeRentalUnit($user);
        $tenant = $this->makeTenant($user->groups()->first()->id);
        $wohnungLease = $wohnung->leases()->create([
            'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $wohnungLease->tenants()->attach($tenant->id);

        $secondWohnung = $wohnung->managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'is_relevant_for_billing' => true]);

        $response = $this->actingAs($user)->post(route('leases.store'), [
            'rental_unit_id' => $secondWohnung->id,
            'assignment_mode' => 'reference',
            'coupled_to_lease_id' => $wohnungLease->id,
            'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 30,
        ]);

        $response->assertSessionHasErrors('coupled_to_lease_id');
        $this->assertSame(0, $secondWohnung->leases()->count());
    }

    public function test_coupling_to_a_lease_of_a_non_abrechnungsrelevante_mieteinheit_is_rejected(): void
    {
        $user = User::factory()->create();
        $anchor = $this->makeRentalUnit($user);
        $stellplatzA = $anchor->managementUnit->rentalUnits()->create(['type' => 'Stellplatz', 'is_relevant_for_billing' => false]);
        $tenant = $this->makeTenant($user->groups()->first()->id);
        $stellplatzALease = $stellplatzA->leases()->create([
            'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 30,
        ]);
        $stellplatzALease->tenants()->attach($tenant->id);

        $stellplatzB = $anchor->managementUnit->rentalUnits()->create(['type' => 'Stellplatz', 'is_relevant_for_billing' => false]);

        $response = $this->actingAs($user)->post(route('leases.store'), [
            'rental_unit_id' => $stellplatzB->id,
            'assignment_mode' => 'reference',
            'coupled_to_lease_id' => $stellplatzALease->id,
            'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 30,
        ]);

        $response->assertSessionHasErrors('coupled_to_lease_id');
    }

    public function test_coupling_to_a_lease_of_another_management_unit_is_rejected(): void
    {
        $user = User::factory()->create();
        $wohnung = $this->makeRentalUnit($user);
        $tenant = $this->makeTenant($user->groups()->first()->id);
        $wohnungLease = $wohnung->leases()->create([
            'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $wohnungLease->tenants()->attach($tenant->id);

        $otherManagementUnit = $wohnung->managementUnit->managementGroup->managementUnits()->create([
            'street' => 'B', 'postal_code' => '2', 'city' => 'C',
        ]);
        $garage = $otherManagementUnit->rentalUnits()->create(['type' => 'Garage', 'is_relevant_for_billing' => false]);

        $response = $this->actingAs($user)->post(route('leases.store'), [
            'rental_unit_id' => $garage->id,
            'assignment_mode' => 'reference',
            'coupled_to_lease_id' => $wohnungLease->id,
            'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 30,
        ]);

        $response->assertSessionHasErrors('coupled_to_lease_id');
    }

    public function test_coupling_to_a_nonexistent_lease_is_rejected(): void
    {
        $user = User::factory()->create();
        $anchor = $this->makeRentalUnit($user);
        $garage = $anchor->managementUnit->rentalUnits()->create(['type' => 'Garage', 'is_relevant_for_billing' => false]);

        $response = $this->actingAs($user)->post(route('leases.store'), [
            'rental_unit_id' => $garage->id,
            'assignment_mode' => 'reference',
            'coupled_to_lease_id' => 999999,
            'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 30,
        ]);

        $response->assertSessionHasErrors('coupled_to_lease_id');
    }

    public function test_coupling_to_a_lease_of_the_same_mieteinheit_is_rejected(): void
    {
        $user = User::factory()->create();
        $garage = $this->makeRentalUnit($user);
        $garage->update(['is_relevant_for_billing' => false]);
        $tenant = $this->makeTenant($user->groups()->first()->id);
        $existingLease = $garage->leases()->create([
            'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 30,
        ]);
        $existingLease->tenants()->attach($tenant->id);

        $response = $this->actingAs($user)->post(route('leases.store'), [
            'rental_unit_id' => $garage->id,
            'assignment_mode' => 'reference',
            'coupled_to_lease_id' => $existingLease->id,
            'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 30,
        ]);

        $response->assertSessionHasErrors('coupled_to_lease_id');
    }

    /**
     * Konzept.md "### Detailbild Mieteinheit": "Der Referenzierende Mietvertrag endet
     * spätestens mit dem referenzierten Mietvertrag."
     */
    public function test_a_coupled_lease_ending_after_its_referenced_lease_is_rejected(): void
    {
        $user = User::factory()->create();
        $wohnung = $this->makeRentalUnit($user);
        $tenant = $this->makeTenant($user->groups()->first()->id);
        $wohnungLease = $wohnung->leases()->create([
            'start_date' => '2026-01-01', 'end_date' => '2026-06-30', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $wohnungLease->tenants()->attach($tenant->id);

        $garage = $wohnung->managementUnit->rentalUnits()->create(['type' => 'Garage', 'is_relevant_for_billing' => false]);

        $response = $this->actingAs($user)->post(route('leases.store'), [
            'rental_unit_id' => $garage->id,
            'assignment_mode' => 'reference',
            'coupled_to_lease_id' => $wohnungLease->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 30,
        ]);

        $response->assertSessionHasErrors('coupled_to_lease_id');
    }

    public function test_the_show_page_can_be_rendered_and_generates_rent_payments(): void
    {
        $user = User::factory()->create();
        $rentalUnit = $this->makeRentalUnit($user);
        $tenant = $this->makeTenant($user->groups()->first()->id);
        $lease = $rentalUnit->leases()->create([
            'start_date' => now()->startOfYear()->toDateString(),
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);

        $response = $this->actingAs($user)->get(route('leases.show', $lease));

        $response->assertOk();
        $this->assertGreaterThan(0, $lease->leaseIntervals->first()->rentPayments()->count());
    }

    public function test_the_edit_form_can_be_rendered(): void
    {
        $user = User::factory()->create();
        $rentalUnit = $this->makeRentalUnit($user);
        $tenant = $this->makeTenant($user->groups()->first()->id);
        $lease = $rentalUnit->leases()->create([
            'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);

        $response = $this->actingAs($user)->get(route('leases.edit', $lease));

        $response->assertOk();
    }

    public function test_a_lease_can_be_updated(): void
    {
        $user = User::factory()->create();
        $rentalUnit = $this->makeRentalUnit($user);
        $tenant = $this->makeTenant($user->groups()->first()->id);
        $lease = $rentalUnit->leases()->create([
            'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);

        $response = $this->actingAs($user)->put(route('leases.update', $lease), [
            'tenant_ids' => [$tenant->id],
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 600,
        ]);

        $response->assertRedirect(route('leases.show', $lease));
        $this->assertEquals(600, $lease->fresh()->cold_rent);
    }

    public function test_updating_a_lease_end_date_before_the_current_intervals_start_is_rejected(): void
    {
        $user = User::factory()->create();
        $rentalUnit = $this->makeRentalUnit($user);
        $tenant = $this->makeTenant($user->groups()->first()->id);
        $lease = $rentalUnit->leases()->create([
            'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);
        $lease->leaseIntervals()->create([
            'start_date' => '2026-06-01', 'rent_amount' => 550, 'utility_advance_payment' => 0, 'occupant_count' => 1,
        ]);

        $response = $this->actingAs($user)->put(route('leases.update', $lease), [
            'tenant_ids' => [$tenant->id],
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-01',
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 500,
        ]);

        $response->assertSessionHasErrors('end_date');
    }

    public function test_updating_a_lease_to_overlap_another_lease_is_rejected(): void
    {
        $user = User::factory()->create();
        $rentalUnit = $this->makeRentalUnit($user);
        $tenant = $this->makeTenant($user->groups()->first()->id);
        $rentalUnit->leases()->create([
            'start_date' => '2027-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease = $rentalUnit->leases()->create([
            'start_date' => '2026-01-01', 'end_date' => '2026-06-30',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);

        $response = $this->actingAs($user)->put(route('leases.update', $lease), [
            'tenant_ids' => [$tenant->id],
            'start_date' => '2026-01-01',
            'end_date' => '2027-06-01',
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 500,
        ]);

        $response->assertSessionHasErrors('start_date');
    }

    public function test_updating_a_lease_is_forbidden_for_an_outsider(): void
    {
        $owner = User::factory()->create();
        $rentalUnit = $this->makeRentalUnit($owner);
        $tenant = $this->makeTenant($owner->groups()->first()->id);
        $lease = $rentalUnit->leases()->create([
            'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->put(route('leases.update', $lease), [
            'tenant_ids' => [$tenant->id],
            'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 999,
        ]);

        $response->assertForbidden();
        $this->assertEquals(500, $lease->fresh()->cold_rent);
    }

    public function test_a_lease_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $rentalUnit = $this->makeRentalUnit($user);
        $tenant = $this->makeTenant($user->groups()->first()->id);
        $lease = $rentalUnit->leases()->create([
            'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $lease->tenants()->attach($tenant->id);

        $response = $this->actingAs($user)->delete(route('leases.destroy', $lease));

        $response->assertRedirect(route('rental-units.show', $rentalUnit));
        $this->assertNull(Lease::find($lease->id));
    }
}
