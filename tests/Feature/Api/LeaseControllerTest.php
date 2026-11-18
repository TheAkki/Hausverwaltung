<?php

namespace Tests\Feature\Api;

use App\Models\Lease;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

// KI-generiert
class LeaseControllerTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function terms(array $overrides = []): array
    {
        return [
            'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 650, ...$overrides,
        ];
    }

    public function test_the_index_hides_expired_leases_unless_requested(): void
    {
        $user = $this->apiUser();
        $this->makeLease($user);
        $this->makeLease($user, attributes: ['start_date' => '2020-01-01', 'end_date' => '2020-12-31']);
        $this->makeLease(User::factory()->create());

        $this->getJson(route('api.leases.index'))->assertOk()->assertJsonCount(1);
        $this->getJson(route('api.leases.index', ['show_expired' => 1]))->assertOk()->assertJsonCount(2);
    }

    public function test_a_lease_can_be_created_with_its_own_tenants(): void
    {
        $user = $this->apiUser();
        $rentalUnit = $this->makeRentalUnit($user);
        $tenant = $this->makeTenant($user);

        $response = $this->postJson(route('api.leases.store'), $this->terms([
            'rental_unit_id' => $rentalUnit->id, 'tenant_ids' => [$tenant->id],
        ]));

        $response->assertCreated();
        $this->assertSame([$tenant->id], Lease::find($response->json('id'))->tenants->pluck('id')->all());
    }

    public function test_a_lease_can_be_coupled_to_another_lease_and_inherits_its_tenants(): void
    {
        $user = $this->apiUser();
        $managementUnit = $this->makeManagementUnit($user);
        $apartmentLease = $this->makeLease($user, $this->makeRentalUnit($user, true, $managementUnit));
        $garage = $this->makeRentalUnit($user, false, $managementUnit);

        $response = $this->postJson(route('api.leases.store'), $this->terms([
            'rental_unit_id' => $garage->id, 'assignment_mode' => 'reference', 'coupled_to_lease_id' => $apartmentLease->id,
        ]));

        $response->assertCreated()->assertJsonPath('coupled_to_lease_id', $apartmentLease->id);
        $this->assertEquals(
            $apartmentLease->tenants->pluck('id')->all(),
            Lease::find($response->json('id'))->tenants->pluck('id')->all(),
        );
    }

    public function test_an_invalid_coupling_is_rejected(): void
    {
        $user = $this->apiUser();
        $apartment = $this->makeRentalUnit($user);
        $otherLease = $this->makeLease($user, $this->makeRentalUnit($user));

        $this->postJson(route('api.leases.store'), $this->terms([
            'rental_unit_id' => $apartment->id, 'assignment_mode' => 'reference', 'coupled_to_lease_id' => $otherLease->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('coupled_to_lease_id');
    }

    public function test_an_overlapping_lease_is_rejected(): void
    {
        $user = $this->apiUser();
        $existing = $this->makeLease($user);
        $tenant = $this->makeTenant($user);

        $this->postJson(route('api.leases.store'), $this->terms([
            'rental_unit_id' => $existing->rental_unit_id, 'tenant_ids' => [$tenant->id], 'start_date' => '2026-06-01',
        ]))->assertUnprocessable()->assertJsonValidationErrors('start_date');
    }

    public function test_tenants_of_a_foreign_group_cannot_be_assigned(): void
    {
        $user = $this->apiUser();
        $rentalUnit = $this->makeRentalUnit($user);
        $foreignTenant = $this->makeTenant(User::factory()->create());

        $this->postJson(route('api.leases.store'), $this->terms([
            'rental_unit_id' => $rentalUnit->id, 'tenant_ids' => [$foreignTenant->id],
        ]))->assertUnprocessable()->assertJsonValidationErrors('tenant_ids.0');
    }

    public function test_a_lease_cannot_be_created_for_a_foreign_rental_unit(): void
    {
        $rentalUnit = $this->makeRentalUnit(User::factory()->create());
        $user = $this->apiUser();
        $tenant = $this->makeTenant($user);

        $this->postJson(route('api.leases.store'), $this->terms([
            'rental_unit_id' => $rentalUnit->id, 'tenant_ids' => [$tenant->id],
        ]))->assertForbidden();
        $this->assertSame(0, Lease::count());
    }

    public function test_a_lease_can_be_shown(): void
    {
        $user = $this->apiUser();
        $lease = $this->makeLease($user);

        $this->getJson(route('api.leases.show', $lease))
            ->assertOk()->assertJsonPath('tenants.0.last_name', 'Mustermann')
            ->assertJsonPath('rental_unit.management_unit.management_group.id', $lease->rentalUnit->managementUnit->management_group_id);
    }

    public function test_a_foreign_lease_cannot_be_shown(): void
    {
        $lease = $this->makeLease(User::factory()->create());
        $this->apiUser();

        $this->getJson(route('api.leases.show', $lease))->assertForbidden();
    }

    public function test_a_lease_can_be_updated_and_its_tenants_synced(): void
    {
        $user = $this->apiUser();
        $lease = $this->makeLease($user);
        $newTenant = $this->makeTenant($user, 'Neumieter');

        $response = $this->putJson(route('api.leases.update', $lease), $this->terms([
            'tenant_ids' => [$newTenant->id], 'cold_rent' => 700, 'end_date' => '2027-12-31',
        ]));

        $response->assertOk();
        $lease->refresh();
        $this->assertEquals(700, $lease->cold_rent);
        $this->assertSame([$newTenant->id], $lease->tenants->pluck('id')->all());
    }

    public function test_an_end_date_before_the_start_date_is_rejected(): void
    {
        $user = $this->apiUser();
        $lease = $this->makeLease($user);

        $this->putJson(route('api.leases.update', $lease), $this->terms([
            'tenant_ids' => $lease->tenants->pluck('id')->all(), 'end_date' => '2025-01-01',
        ]))->assertUnprocessable()->assertJsonValidationErrors('end_date');
    }

    public function test_a_lease_can_be_deleted(): void
    {
        $user = $this->apiUser();
        $lease = $this->makeLease($user);

        $this->deleteJson(route('api.leases.destroy', $lease))->assertNoContent();
        $this->assertNull(Lease::find($lease->id));
    }
}
