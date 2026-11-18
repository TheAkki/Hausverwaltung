<?php

namespace Tests\Feature\Api;

use App\Models\RentalUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

// KI-generiert
class RentalUnitControllerTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    public function test_the_index_only_lists_visible_rental_units(): void
    {
        $user = $this->apiUser();
        $this->makeRentalUnit($user);
        $this->makeRentalUnit(User::factory()->create());

        $this->getJson(route('api.rental-units.index'))->assertOk()->assertJsonCount(1);
    }

    public function test_a_rental_unit_can_be_created(): void
    {
        $user = $this->apiUser();
        $managementUnit = $this->makeManagementUnit($user);

        $response = $this->postJson(route('api.rental-units.store'), [
            'management_unit_id' => $managementUnit->id, 'type' => 'Wohnung', 'size' => 55.5,
            'floor' => 1, 'is_relevant_for_billing' => true,
        ]);

        $response->assertCreated()->assertJsonPath('type', 'Wohnung')->assertJsonPath('is_relevant_for_billing', true);
    }

    public function test_a_rental_unit_cannot_be_created_in_a_foreign_management_unit(): void
    {
        $managementUnit = $this->makeManagementUnit(User::factory()->create());
        $this->apiUser();

        $this->postJson(route('api.rental-units.store'), [
            'management_unit_id' => $managementUnit->id, 'type' => 'Wohnung',
        ])->assertForbidden();
        $this->assertSame(0, RentalUnit::count());
    }

    public function test_a_missing_type_fails_validation(): void
    {
        $user = $this->apiUser();
        $managementUnit = $this->makeManagementUnit($user);

        $this->postJson(route('api.rental-units.store'), ['management_unit_id' => $managementUnit->id])
            ->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    public function test_a_rental_unit_can_be_shown(): void
    {
        $user = $this->apiUser();
        $lease = $this->makeLease($user);

        $this->getJson(route('api.rental-units.show', $lease->rentalUnit))
            ->assertOk()->assertJsonPath('leases.0.tenants.0.last_name', 'Mustermann');
    }

    public function test_a_rental_unit_can_be_updated(): void
    {
        $user = $this->apiUser();
        $rentalUnit = $this->makeRentalUnit($user, relevant: false);

        $response = $this->putJson(route('api.rental-units.update', $rentalUnit), [
            'type' => 'Stellplatz', 'is_relevant_for_billing' => true,
        ]);

        $response->assertOk()->assertJsonPath('type', 'Stellplatz')->assertJsonPath('is_relevant_for_billing', true);
    }

    public function test_a_foreign_rental_unit_cannot_be_updated(): void
    {
        $rentalUnit = $this->makeRentalUnit(User::factory()->create());
        $this->apiUser();

        $this->putJson(route('api.rental-units.update', $rentalUnit), ['type' => 'X'])->assertForbidden();
    }

    public function test_a_rental_unit_can_be_deleted(): void
    {
        $user = $this->apiUser();
        $rentalUnit = $this->makeRentalUnit($user);

        $this->deleteJson(route('api.rental-units.destroy', $rentalUnit))->assertNoContent();
        $this->assertNull(RentalUnit::find($rentalUnit->id));
    }
}
