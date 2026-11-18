<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\RentPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RentPaymentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Covers two related bugs: a Mietintervall ending up with end_date before start_date
 * (via ending the Lease earlier than a later interval's own start), and stale
 * RentPayment rows surviving after a Mietvertrag/-intervall is shortened.
 */
class LeaseIntervalEndDateTest extends TestCase
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

    public function test_ending_a_lease_before_the_current_intervals_start_is_rejected(): void
    {
        $user = User::factory()->create();
        $lease = $this->makeLease($user);

        $first = $lease->leaseIntervals->first();
        $lease->leaseIntervals()->create([
            'start_date' => '2026-06-01', 'rent_amount' => 550, 'occupant_count' => 1, 'utility_advance_payment' => 100,
        ]);
        $first->update(['end_date' => '2026-05-31']);

        // The current (second) interval starts 2026-06-01; ending the lease on
        // 2026-03-01 would otherwise leave that interval with end_date < start_date.
        $response = $this->actingAs($user)->put(route('leases.update', $lease), [
            'tenant_ids' => [$lease->tenants->first()->id],
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-01',
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 500,
        ]);

        $response->assertSessionHasErrors('end_date');
        $this->assertNull($lease->fresh()->end_date);
        $this->assertNull($lease->leaseIntervals()->orderBy('start_date', 'desc')->first()->end_date);
    }

    public function test_ending_a_lease_on_or_after_the_current_intervals_start_is_accepted(): void
    {
        $user = User::factory()->create();
        $lease = $this->makeLease($user);

        $first = $lease->leaseIntervals->first();
        $second = $lease->leaseIntervals()->create([
            'start_date' => '2026-06-01', 'rent_amount' => 550, 'occupant_count' => 1, 'utility_advance_payment' => 100,
        ]);
        $first->update(['end_date' => '2026-05-31']);

        $response = $this->actingAs($user)->put(route('leases.update', $lease), [
            'tenant_ids' => [$lease->tenants->first()->id],
            'start_date' => '2026-01-01',
            'end_date' => '2026-08-15',
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 500,
        ]);

        $response->assertRedirect(route('leases.show', $lease));
        $this->assertSame('2026-08-15', $second->fresh()->end_date->toDateString());
    }

    public function test_shortening_a_lease_deletes_open_rent_payments_beyond_the_new_end_date_but_keeps_booked_ones(): void
    {
        Carbon::setTestNow('2026-06-15');

        $user = User::factory()->create();
        $lease = $this->makeLease($user);
        $interval = $lease->leaseIntervals->first();

        app(RentPaymentGenerator::class)->generate($interval);
        $this->assertSame(6, $interval->rentPayments()->count());

        $january = $interval->rentPayments()->whereDate('period_date', '2026-01-01')->first();
        $january->update(['booking_date' => '2026-01-05']);

        $response = $this->actingAs($user)->put(route('leases.update', $lease), [
            'tenant_ids' => [$lease->tenants->first()->id],
            'start_date' => '2026-01-01',
            'end_date' => '2026-02-15',
            'rent_adjustment_type' => 'fixed',
            'cold_rent' => 500,
        ]);

        $response->assertRedirect(route('leases.show', $lease));

        // January (booked) and February (still within the new end date's month) survive;
        // March through June (open, now beyond the shortened lease) are gone.
        $remaining = $interval->rentPayments()->orderBy('period_date')->pluck('period_date')
            ->map(fn ($d) => $d->toDateString());
        $this->assertSame(['2026-01-01', '2026-02-01'], $remaining->all());
        $this->assertNotNull(RentPayment::find($january->id));

        Carbon::setTestNow();
    }

    public function test_creating_a_new_lease_interval_deletes_open_rent_payments_beyond_the_previous_intervals_new_end_date(): void
    {
        Carbon::setTestNow('2026-06-15');

        $user = User::factory()->create();
        $lease = $this->makeLease($user);
        $first = $lease->leaseIntervals->first();

        app(RentPaymentGenerator::class)->generate($first);
        $this->assertSame(6, $first->rentPayments()->count());

        $march = $first->rentPayments()->whereDate('period_date', '2026-03-01')->first();
        $march->update(['booking_date' => '2026-03-03']);

        // Starting a new Mietintervall on 2026-04-01 (e.g. because the Nebenkosten
        // changed) truncates the first interval's end_date to 2026-03-31 — the
        // now-superfluous open April/May/June payments must be removed, but the
        // already booked March payment must survive.
        $response = $this->actingAs($user)->post(route('lease-intervals.store'), [
            'lease_id' => $lease->id,
            'start_date' => '2026-04-01',
            'rent_amount' => 500,
            'occupant_count' => 1,
            'utility_advance_payment' => 150,
        ]);

        $response->assertStatus(302);
        $this->assertSame('2026-03-31', $first->fresh()->end_date->toDateString());

        $remaining = $first->rentPayments()->orderBy('period_date')->pluck('period_date')
            ->map(fn ($d) => $d->toDateString());
        $this->assertSame(['2026-01-01', '2026-02-01', '2026-03-01'], $remaining->all());
        $this->assertNotNull(RentPayment::find($march->id));

        Carbon::setTestNow();
    }
}
