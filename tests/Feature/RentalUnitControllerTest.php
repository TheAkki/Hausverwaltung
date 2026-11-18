<?php

namespace Tests\Feature;

use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\RentalUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers CRUD for Mieteinheiten (rental units), scoped to the owning management
 * group's visibility via authorizeResource + RentalUnitPolicy.
 */
class RentalUnitControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeManagementUnit(User $user): ManagementUnit
    {
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);

        return $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
    }

    public function test_the_index_only_lists_rental_units_of_visible_management_groups(): void
    {
        $user = User::factory()->create();
        $managementUnit = $this->makeManagementUnit($user);
        $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'is_relevant_for_billing' => true]);

        $outsider = User::factory()->create();
        $outsiderUnit = $this->makeManagementUnit($outsider);
        $outsiderUnit->rentalUnits()->create(['type' => 'Garage', 'is_relevant_for_billing' => false]);

        $response = $this->actingAs($user)->get(route('rental-units.index'));

        $response->assertOk();
        $this->assertCount(1, $response->viewData('rentalUnits'));
    }

    public function test_the_create_form_can_be_rendered_for_a_visible_management_unit(): void
    {
        $user = User::factory()->create();
        $managementUnit = $this->makeManagementUnit($user);

        $response = $this->actingAs($user)->get(route('rental-units.create', ['management_unit_id' => $managementUnit->id]));

        $response->assertOk();
    }

    public function test_the_create_form_is_forbidden_for_a_management_unit_of_another_group(): void
    {
        $owner = User::factory()->create();
        $managementUnit = $this->makeManagementUnit($owner);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->get(route('rental-units.create', ['management_unit_id' => $managementUnit->id]));

        $response->assertForbidden();
    }

    public function test_the_show_page_can_be_rendered(): void
    {
        $user = User::factory()->create();
        $managementUnit = $this->makeManagementUnit($user);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'is_relevant_for_billing' => true]);

        $response = $this->actingAs($user)->get(route('rental-units.show', $rentalUnit));

        $response->assertOk();
    }

    public function test_the_edit_form_can_be_rendered(): void
    {
        $user = User::factory()->create();
        $managementUnit = $this->makeManagementUnit($user);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'is_relevant_for_billing' => true]);

        $response = $this->actingAs($user)->get(route('rental-units.edit', $rentalUnit));

        $response->assertOk();
    }

    public function test_a_rental_unit_can_be_created_for_a_visible_management_unit(): void
    {
        $user = User::factory()->create();
        $managementUnit = $this->makeManagementUnit($user);

        $response = $this->actingAs($user)->post(route('rental-units.store'), [
            'management_unit_id' => $managementUnit->id,
            'type' => 'Wohnung',
            'size' => 55.5,
            'description' => 'OG links',
            'floor' => 1,
            'is_relevant_for_billing' => '1',
        ]);

        $rentalUnit = RentalUnit::first();
        $this->assertNotNull($rentalUnit);
        $response->assertRedirect(route('rental-units.show', $rentalUnit));
        $this->assertSame('Wohnung', $rentalUnit->type);
        $this->assertSame('1', $rentalUnit->floor);
        $this->assertTrue($rentalUnit->is_relevant_for_billing);
    }

    public function test_a_non_integer_floor_is_rejected(): void
    {
        $user = User::factory()->create();
        $managementUnit = $this->makeManagementUnit($user);

        $response = $this->actingAs($user)->post(route('rental-units.store'), [
            'management_unit_id' => $managementUnit->id,
            'type' => 'Wohnung',
            'floor' => '1. OG',
        ]);

        $response->assertSessionHasErrors('floor');
        $this->assertSame(0, RentalUnit::count());
    }

    public function test_a_rental_unit_cannot_be_created_for_a_management_unit_of_another_group(): void
    {
        $user = User::factory()->create();
        $outsider = User::factory()->create();
        $outsiderUnit = $this->makeManagementUnit($outsider);

        $response = $this->actingAs($user)->post(route('rental-units.store'), [
            'management_unit_id' => $outsiderUnit->id,
            'type' => 'Wohnung',
        ]);

        $response->assertForbidden();
        $this->assertSame(0, RentalUnit::count());
    }

    public function test_a_rental_unit_can_be_updated(): void
    {
        $user = User::factory()->create();
        $managementUnit = $this->makeManagementUnit($user);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Garage', 'is_relevant_for_billing' => false]);

        $response = $this->actingAs($user)->put(route('rental-units.update', $rentalUnit), [
            'type' => 'Stellplatz',
            'size' => 12,
            'is_relevant_for_billing' => '1',
        ]);

        $response->assertRedirect(route('rental-units.show', $rentalUnit));
        $rentalUnit->refresh();
        $this->assertSame('Stellplatz', $rentalUnit->type);
        $this->assertTrue($rentalUnit->is_relevant_for_billing);
    }

    public function test_a_user_outside_the_group_cannot_update_a_rental_unit(): void
    {
        $owner = User::factory()->create();
        $managementUnit = $this->makeManagementUnit($owner);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Garage']);

        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->put(route('rental-units.update', $rentalUnit), [
            'type' => 'Hijacked',
        ]);

        $response->assertForbidden();
        $this->assertSame('Garage', $rentalUnit->fresh()->type);
    }

    public function test_a_rental_unit_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $managementUnit = $this->makeManagementUnit($user);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Garage']);

        $response = $this->actingAs($user)->delete(route('rental-units.destroy', $rentalUnit));

        $response->assertRedirect(route('management-units.show', $managementUnit));
        $this->assertNull(RentalUnit::find($rentalUnit->id));
    }

    public function test_creating_a_rental_unit_without_a_type_fails_validation(): void
    {
        $user = User::factory()->create();
        $managementUnit = $this->makeManagementUnit($user);

        $response = $this->actingAs($user)->post(route('rental-units.store'), [
            'management_unit_id' => $managementUnit->id,
        ]);

        $response->assertSessionHasErrors('type');
        $this->assertSame(0, RentalUnit::count());
    }
}
