<?php

namespace Tests\Feature\Api;

use App\Models\ManagementUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

// KI-generiert
class ManagementUnitControllerTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function address(): array
    {
        return ['street' => 'Neue Str 2', 'postal_code' => '22222', 'city' => 'Neustadt'];
    }

    public function test_the_index_only_lists_visible_management_units(): void
    {
        $user = $this->apiUser();
        $this->makeManagementUnit($user);
        $this->makeManagementUnit(User::factory()->create());

        $this->getJson(route('api.management-units.index'))->assertOk()->assertJsonCount(1);
    }

    public function test_a_management_unit_can_be_created(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);

        $response = $this->postJson(route('api.management-units.store'), [
            'management_group_id' => $managementGroup->id, ...$this->address(),
        ]);

        $response->assertCreated()->assertJsonPath('city', 'Neustadt');
    }

    public function test_a_management_unit_cannot_be_created_in_a_foreign_management_group(): void
    {
        $managementGroup = $this->makeManagementGroup(User::factory()->create());
        $this->apiUser();

        $this->postJson(route('api.management-units.store'), [
            'management_group_id' => $managementGroup->id, ...$this->address(),
        ])->assertNotFound();
        $this->assertSame(0, ManagementUnit::count());
    }

    public function test_a_missing_address_fails_validation(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user);

        $this->postJson(route('api.management-units.store'), ['management_group_id' => $managementGroup->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['street', 'postal_code', 'city']);
    }

    public function test_a_management_unit_can_be_shown(): void
    {
        $user = $this->apiUser();
        $lease = $this->makeLease($user);

        $this->getJson(route('api.management-units.show', $lease->rentalUnit->managementUnit))
            ->assertOk()->assertJsonPath('rental_units.0.leases.0.id', $lease->id);
    }

    public function test_a_management_unit_can_be_updated(): void
    {
        $user = $this->apiUser();
        $managementUnit = $this->makeManagementUnit($user);

        $this->putJson(route('api.management-units.update', $managementUnit), $this->address())
            ->assertOk()->assertJsonPath('street', 'Neue Str 2');
    }

    public function test_a_foreign_management_unit_cannot_be_updated(): void
    {
        $managementUnit = $this->makeManagementUnit(User::factory()->create());
        $this->apiUser();

        $this->putJson(route('api.management-units.update', $managementUnit), $this->address())->assertForbidden();
    }

    public function test_a_management_unit_can_be_deleted(): void
    {
        $user = $this->apiUser();
        $managementUnit = $this->makeManagementUnit($user);

        $this->deleteJson(route('api.management-units.destroy', $managementUnit))->assertNoContent();
        $this->assertNull(ManagementUnit::find($managementUnit->id));
    }
}
