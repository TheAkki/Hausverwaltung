<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaseIntervalDeletionTest extends TestCase
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

    public function test_the_last_lease_interval_can_be_deleted_and_reopens_the_previous_one(): void
    {
        $user = User::factory()->create();
        $lease = $this->makeLease($user);

        $first = $lease->leaseIntervals->first();

        $second = $lease->leaseIntervals()->create([
            'start_date' => '2026-06-01',
            'rent_amount' => 550,
            'occupant_count' => 1,
            'utility_advance_payment' => 100,
        ]);
        $first->update(['end_date' => '2026-05-31']);

        $response = $this->actingAs($user)->delete(route('lease-intervals.destroy', $second));

        $response->assertRedirect(route('leases.show', $lease));
        $this->assertNull($first->fresh()->end_date);
        $this->assertDatabaseMissing('lease_intervals', ['id' => $second->id]);
    }

    public function test_the_only_lease_interval_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $lease = $this->makeLease($user);
        $only = $lease->leaseIntervals->first();

        $response = $this->actingAs($user)->delete(route('lease-intervals.destroy', $only));

        $response->assertSessionHasErrors('lease_interval');
        $this->assertDatabaseHas('lease_intervals', ['id' => $only->id]);
    }

    public function test_a_non_last_lease_interval_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $lease = $this->makeLease($user);

        $first = $lease->leaseIntervals->first();
        $lease->leaseIntervals()->create([
            'start_date' => '2026-06-01',
            'rent_amount' => 550,
            'occupant_count' => 1,
            'utility_advance_payment' => 100,
        ]);
        $first->update(['end_date' => '2026-05-31']);

        $response = $this->actingAs($user)->delete(route('lease-intervals.destroy', $first));

        $response->assertSessionHasErrors('lease_interval');
        $this->assertDatabaseHas('lease_intervals', ['id' => $first->id]);
    }
}
