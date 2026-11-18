<?php

namespace Tests\Feature\Api\Concerns;

use App\Models\Group;
use App\Models\InvoiceIssuer;
use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\ManagementUnit;
use App\Models\RentalUnit;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// KI-generiert
trait CreatesApiFixtures
{
    protected function apiUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    protected function groupOf(User $user): Group
    {
        return $user->groups()->first();
    }

    protected function makeManagementGroup(User $user, bool $kontosystem = false): ManagementGroup
    {
        return ManagementGroup::create([
            'description' => 'MG', 'user_group_id' => $this->groupOf($user)->id,
            'kontosystem_enabled' => $kontosystem,
        ]);
    }

    protected function makeManagementUnit(User $user, ?ManagementGroup $managementGroup = null): ManagementUnit
    {
        $managementGroup ??= $this->makeManagementGroup($user);

        return $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
    }

    protected function makeRentalUnit(User $user, bool $relevant = true, ?ManagementUnit $managementUnit = null): RentalUnit
    {
        $managementUnit ??= $this->makeManagementUnit($user);

        return $managementUnit->rentalUnits()->create([
            'type' => $relevant ? 'Wohnung' : 'Garage', 'size' => 50, 'is_relevant_for_billing' => $relevant,
        ]);
    }

    protected function makeTenant(User $user, string $lastName = 'Mustermann'): Tenant
    {
        return Tenant::create([
            'user_group_id' => $this->groupOf($user)->id, 'first_name' => 'Max', 'last_name' => $lastName,
            'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ]);
    }

    protected function makeLease(User $user, ?RentalUnit $rentalUnit = null, array $attributes = []): Lease
    {
        $rentalUnit ??= $this->makeRentalUnit($user);

        $lease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500, ...$attributes,
        ]);
        $lease->tenants()->attach($this->makeTenant($user)->id);

        return $lease;
    }

    protected function makeIssuer(User $user): InvoiceIssuer
    {
        return InvoiceIssuer::create([
            'user_group_id' => $this->groupOf($user)->id, 'name' => 'Stadtwerke',
            'street' => 'A', 'postal_code' => '1', 'city' => 'B',
        ]);
    }
}
