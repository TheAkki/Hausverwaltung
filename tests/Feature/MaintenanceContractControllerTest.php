<?php

namespace Tests\Feature;

use App\Models\MaintenanceContract;
use App\Models\ManagementGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceContractControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeManagementGroup(User $user): ManagementGroup
    {
        $group = $user->groups()->first();

        return ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
    }

    private function makeContract(ManagementGroup $managementGroup): MaintenanceContract
    {
        return $managementGroup->maintenanceContracts()->create([
            'name' => 'Rauchwarnanlage',
            'type' => 'RWA',
            'provider' => 'Techem',
            'start_date' => '2024-01-01',
        ]);
    }

    public function test_the_create_form_can_be_rendered_for_a_visible_management_group(): void
    {
        $user = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($user);

        $response = $this->actingAs($user)->get(route('maintenance-contracts.create', ['management_group_id' => $managementGroup->id]));

        $response->assertOk();
    }

    public function test_the_create_form_requires_view_access_to_the_management_group(): void
    {
        $owner = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($owner);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->get(route('maintenance-contracts.create', ['management_group_id' => $managementGroup->id]));

        $response->assertNotFound();
    }

    public function test_a_maintenance_contract_can_be_created(): void
    {
        $user = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($user);

        $response = $this->actingAs($user)->post(route('maintenance-contracts.store'), [
            'management_group_id' => $managementGroup->id,
            'name' => 'Rauchwarnanlage',
            'type' => 'RWA',
            'provider' => 'Techem',
            'start_date' => '2024-01-01',
            'end_date' => '2029-01-01',
        ]);

        $response->assertRedirect(route('management-groups.show', $managementGroup));
        $this->assertSame(1, $managementGroup->maintenanceContracts()->count());
    }

    public function test_an_end_date_before_the_start_date_is_rejected_on_create(): void
    {
        $user = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($user);

        $response = $this->actingAs($user)->post(route('maintenance-contracts.store'), [
            'management_group_id' => $managementGroup->id,
            'name' => 'Rauchwarnanlage',
            'type' => 'RWA',
            'provider' => 'Techem',
            'start_date' => '2024-01-01',
            'end_date' => '2023-01-01',
        ]);

        $response->assertSessionHasErrors('end_date');
        $this->assertSame(0, $managementGroup->maintenanceContracts()->count());
    }

    public function test_a_user_outside_the_group_cannot_create_a_maintenance_contract_for_it(): void
    {
        $owner = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($owner);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->post(route('maintenance-contracts.store'), [
            'management_group_id' => $managementGroup->id,
            'name' => 'Rauchwarnanlage',
            'type' => 'RWA',
            'provider' => 'Techem',
            'start_date' => '2024-01-01',
        ]);

        $response->assertNotFound();
    }

    public function test_the_edit_form_can_be_rendered_by_a_group_member(): void
    {
        $user = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($user);
        $contract = $this->makeContract($managementGroup);

        $response = $this->actingAs($user)->get(route('maintenance-contracts.edit', $contract));

        $response->assertOk();
    }

    public function test_the_edit_form_is_forbidden_for_an_outsider(): void
    {
        $owner = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($owner);
        $contract = $this->makeContract($managementGroup);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->get(route('maintenance-contracts.edit', $contract));

        $response->assertForbidden();
    }

    public function test_a_maintenance_contract_can_be_updated(): void
    {
        $user = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($user);
        $contract = $this->makeContract($managementGroup);

        $response = $this->actingAs($user)->put(route('maintenance-contracts.update', $contract), [
            'name' => 'Aufzugswartung',
            'type' => 'Aufzug',
            'provider' => 'Schindler',
            'start_date' => '2024-02-01',
        ]);

        $response->assertRedirect(route('management-groups.show', $managementGroup));
        $this->assertSame('Aufzugswartung', $contract->fresh()->name);
    }

    public function test_an_end_date_before_the_start_date_is_rejected_on_update(): void
    {
        $user = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($user);
        $contract = $this->makeContract($managementGroup);

        $response = $this->actingAs($user)->put(route('maintenance-contracts.update', $contract), [
            'name' => 'Aufzugswartung',
            'type' => 'Aufzug',
            'provider' => 'Schindler',
            'start_date' => '2024-02-01',
            'end_date' => '2024-01-01',
        ]);

        $response->assertSessionHasErrors('end_date');
    }

    public function test_updating_a_maintenance_contract_is_forbidden_for_an_outsider(): void
    {
        $owner = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($owner);
        $contract = $this->makeContract($managementGroup);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->put(route('maintenance-contracts.update', $contract), [
            'name' => 'Aufzugswartung',
            'type' => 'Aufzug',
            'provider' => 'Schindler',
            'start_date' => '2024-02-01',
        ]);

        $response->assertForbidden();
    }

    public function test_a_maintenance_contract_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($user);
        $contract = $this->makeContract($managementGroup);

        $response = $this->actingAs($user)->delete(route('maintenance-contracts.destroy', $contract));

        $response->assertRedirect(route('management-groups.show', $managementGroup));
        $this->assertSame(0, $managementGroup->maintenanceContracts()->count());
    }

    public function test_deleting_a_maintenance_contract_is_forbidden_for_an_outsider(): void
    {
        $owner = User::factory()->create();
        $managementGroup = $this->makeManagementGroup($owner);
        $contract = $this->makeContract($managementGroup);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->delete(route('maintenance-contracts.destroy', $contract));

        $response->assertForbidden();
        $this->assertSame(1, $managementGroup->maintenanceContracts()->count());
    }
}
