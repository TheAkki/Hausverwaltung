<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the create/edit forms and authorization for Mietintervalle, plus the
 * store() rejection when a new interval doesn't start after the latest one.
 */
class LeaseIntervalControllerTest extends TestCase
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

    public function test_the_create_form_can_be_rendered_for_a_visible_lease(): void
    {
        $user = User::factory()->create();
        $lease = $this->makeLease($user);

        $response = $this->actingAs($user)->get(route('lease-intervals.create', ['lease_id' => $lease->id]));

        $response->assertOk();
    }

    public function test_the_create_form_is_forbidden_for_an_outsider(): void
    {
        $owner = User::factory()->create();
        $lease = $this->makeLease($owner);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->get(route('lease-intervals.create', ['lease_id' => $lease->id]));

        $response->assertForbidden();
    }

    public function test_a_new_interval_must_start_after_the_latest_ones_start(): void
    {
        $user = User::factory()->create();
        $lease = $this->makeLease($user);

        $response = $this->actingAs($user)->post(route('lease-intervals.store'), [
            'lease_id' => $lease->id,
            'start_date' => '2026-01-01',
            'rent_amount' => 500,
            'occupant_count' => 1,
            'utility_advance_payment' => 100,
        ]);

        $response->assertSessionHasErrors('start_date');
        $this->assertSame(1, $lease->leaseIntervals()->count());
    }

    public function test_storing_an_interval_is_forbidden_for_an_outsider(): void
    {
        $owner = User::factory()->create();
        $lease = $this->makeLease($owner);
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->post(route('lease-intervals.store'), [
            'lease_id' => $lease->id,
            'start_date' => '2026-06-01',
            'rent_amount' => 500,
            'occupant_count' => 1,
            'utility_advance_payment' => 100,
        ]);

        $response->assertForbidden();
        $this->assertSame(1, $lease->leaseIntervals()->count());
    }

    public function test_the_edit_form_can_be_rendered_for_a_visible_interval(): void
    {
        $user = User::factory()->create();
        $lease = $this->makeLease($user);
        $interval = $lease->leaseIntervals()->first();

        $response = $this->actingAs($user)->get(route('lease-intervals.edit', $interval));

        $response->assertOk();
    }

    public function test_the_edit_form_is_forbidden_for_an_outsider(): void
    {
        $owner = User::factory()->create();
        $lease = $this->makeLease($owner);
        $interval = $lease->leaseIntervals()->first();
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->get(route('lease-intervals.edit', $interval));

        $response->assertForbidden();
    }

    public function test_an_interval_can_be_updated(): void
    {
        $user = User::factory()->create();
        $lease = $this->makeLease($user);
        $interval = $lease->leaseIntervals()->first();

        $response = $this->actingAs($user)->put(route('lease-intervals.update', $interval), [
            'start_date' => '2026-01-01',
            'rent_amount' => 550,
            'occupant_count' => 2,
            'utility_advance_payment' => 120,
        ]);

        $response->assertRedirect(route('leases.show', $lease));
        $this->assertEquals(550, $interval->fresh()->rent_amount);
        $this->assertSame(2, $interval->fresh()->occupant_count);
    }

    public function test_updating_an_interval_is_forbidden_for_an_outsider(): void
    {
        $owner = User::factory()->create();
        $lease = $this->makeLease($owner);
        $interval = $lease->leaseIntervals()->first();
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->put(route('lease-intervals.update', $interval), [
            'start_date' => '2026-01-01',
            'rent_amount' => 999,
            'occupant_count' => 1,
            'utility_advance_payment' => 100,
        ]);

        $response->assertForbidden();
        $this->assertEquals(500, $interval->fresh()->rent_amount);
    }
}
