<?php

namespace Tests\Feature\Api;

use App\Models\LeaseInterval;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

// KI-generiert
class LeaseIntervalRentPaymentControllerTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    private const TODAY = '2026-04-15';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function intervalPayload(array $overrides = []): array
    {
        return ['rent_amount' => 600, 'occupant_count' => 2, 'utility_advance_payment' => 100, ...$overrides];
    }

    public function test_a_new_interval_closes_the_previous_one(): void
    {
        $lease = $this->makeLease($this->apiUser());

        $this->postJson(route('api.lease-intervals.store'), $this->intervalPayload([
            'lease_id' => $lease->id, 'start_date' => '2026-03-01',
        ]))->assertCreated();

        $intervals = $lease->leaseIntervals()->orderBy('start_date')->get();
        $this->assertCount(2, $intervals);
        $this->assertSame('2026-02-28', $intervals[0]->end_date->toDateString());
    }

    public function test_a_new_interval_must_start_after_the_current_one(): void
    {
        $lease = $this->makeLease($this->apiUser());

        $this->postJson(route('api.lease-intervals.store'), $this->intervalPayload([
            'lease_id' => $lease->id, 'start_date' => '2026-01-01',
        ]))->assertUnprocessable()->assertJsonValidationErrors('start_date');
    }

    public function test_an_interval_can_be_updated_and_only_the_last_one_deleted(): void
    {
        $lease = $this->makeLease($this->apiUser());
        $first = $lease->leaseIntervals()->first();

        $this->putJson(route('api.lease-intervals.update', $first), $this->intervalPayload([
            'start_date' => '2026-01-01', 'end_date' => '2026-05-31', 'notes' => 'geändert',
        ]))->assertOk()->assertJsonPath('notes', 'geändert');

        $this->deleteJson(route('api.lease-intervals.destroy', $first))->assertUnprocessable();

        $second = $this->postJson(route('api.lease-intervals.store'), $this->intervalPayload([
            'lease_id' => $lease->id, 'start_date' => '2026-06-01',
        ]))->json('id');

        $this->deleteJson(route('api.lease-intervals.destroy', $first))->assertUnprocessable();
        $this->deleteJson(route('api.lease-intervals.destroy', $second))->assertNoContent();
        $this->assertNull($first->fresh()->end_date);
    }

    public function test_listing_rent_payments_generates_the_elapsed_months(): void
    {
        $lease = $this->makeLease($this->apiUser());
        $interval = $lease->leaseIntervals()->first();

        $this->getJson(route('api.rent-payments.index', $interval))->assertOk()->assertJsonCount(4);
    }

    public function test_a_rent_payment_can_be_booked_and_assigned_to_an_account(): void
    {
        $user = $this->apiUser();
        $managementGroup = $this->makeManagementGroup($user, kontosystem: true);
        $account = $managementGroup->accounts()->create(['name' => 'Giro']);
        $lease = $this->makeLease($user, $this->makeRentalUnit($user, managementUnit: $this->makeManagementUnit($user, $managementGroup)));
        $paymentId = $this->getJson(route('api.rent-payments.index', $lease->leaseIntervals()->first()))->json('0.id');

        $this->putJson(route('api.rent-payments.update', $paymentId), ['booking_date' => '2026-01-03', 'confirm_booking' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('account_id');

        $this->putJson(route('api.rent-payments.update', $paymentId), [
            'booking_date' => '2026-01-03', 'account_id' => $account->id, 'confirm_booking' => true,
        ])->assertOk()->assertJsonPath('accounts.0.id', $account->id);

        $this->putJson(route('api.rent-payments.update', $paymentId), ['booking_date' => null])
            ->assertOk()->assertJsonPath('accounts', []);
    }

    public function test_foreign_intervals_and_payments_are_inaccessible(): void
    {
        $lease = $this->makeLease(User::factory()->create());
        $interval = $lease->leaseIntervals()->first();
        $this->apiUser();

        $this->postJson(route('api.lease-intervals.store'), $this->intervalPayload(['lease_id' => $lease->id, 'start_date' => '2026-03-01']))
            ->assertForbidden();
        $this->putJson(route('api.lease-intervals.update', $interval), $this->intervalPayload(['start_date' => '2026-01-01']))
            ->assertForbidden();
        $this->deleteJson(route('api.lease-intervals.destroy', $interval))->assertForbidden();
        $this->getJson(route('api.rent-payments.index', $interval))->assertForbidden();
        $this->assertInstanceOf(LeaseInterval::class, $interval->fresh());
    }
}
