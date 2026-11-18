<?php

namespace Tests\Feature\Api;

use App\Models\SettlementClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

// KI-generiert
class SettlementClaimControllerTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    public function test_a_claim_for_a_lease_can_be_created_updated_and_deleted(): void
    {
        $lease = $this->makeLease($this->apiUser());
        $intervalId = $lease->rentalUnit->managementUnit->managementGroup->billingIntervals()->first()->id;

        $id = $this->postJson(route('api.settlement-claims.store'), [
            'lease_id' => $lease->id, 'billing_interval_id' => $intervalId, 'date' => '2026-05-01', 'amount' => -42.5,
        ])->assertCreated()->json('id');

        $this->putJson(route('api.settlement-claims.update', $id), [
            'billing_interval_id' => $intervalId, 'date' => '2026-05-02', 'amount' => 10, 'booking_date' => '2026-05-10',
        ])->assertOk()->assertJsonPath('amount', 10);

        $this->deleteJson(route('api.settlement-claims.destroy', $id))->assertNoContent();
        $this->assertNull(SettlementClaim::find($id));
    }

    public function test_a_claim_for_a_vacant_rental_unit_can_be_created(): void
    {
        $rentalUnit = $this->makeRentalUnit($this->apiUser());
        $intervalId = $rentalUnit->managementUnit->managementGroup->billingIntervals()->first()->id;

        $this->postJson(route('api.settlement-claims.store'), [
            'rental_unit_id' => $rentalUnit->id, 'billing_interval_id' => $intervalId, 'date' => '2026-05-01', 'amount' => 5,
        ])->assertCreated()->assertJsonPath('lease_id', null);
    }

    public function test_a_claim_needs_exactly_one_owner_and_an_interval_of_its_group(): void
    {
        $user = $this->apiUser();
        $lease = $this->makeLease($user);
        $foreignInterval = $this->makeManagementGroup($user)->billingIntervals()->first()->id;

        $this->postJson(route('api.settlement-claims.store'), ['date' => '2026-05-01', 'amount' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('lease_id');
        $this->postJson(route('api.settlement-claims.store'), [
            'lease_id' => $lease->id, 'rental_unit_id' => $lease->rental_unit_id, 'date' => '2026-05-01', 'amount' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('lease_id');
        $this->postJson(route('api.settlement-claims.store'), [
            'lease_id' => $lease->id, 'billing_interval_id' => $foreignInterval, 'date' => '2026-05-01', 'amount' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('billing_interval_id');
    }

    public function test_foreign_claims_are_inaccessible(): void
    {
        $owner = User::factory()->create();
        $rentalUnit = $this->makeRentalUnit($owner);
        $claim = SettlementClaim::create([
            'rental_unit_id' => $rentalUnit->id, 'date' => '2026-05-01', 'amount' => 1,
            'billing_interval_id' => $rentalUnit->managementUnit->managementGroup->billingIntervals()->first()->id,
        ]);
        $this->apiUser();

        $this->postJson(route('api.settlement-claims.store'), ['rental_unit_id' => $rentalUnit->id, 'date' => '2026-05-01', 'amount' => 1])
            ->assertForbidden();
        $this->putJson(route('api.settlement-claims.update', $claim), ['date' => '2026-05-01', 'amount' => 1])->assertForbidden();
        $this->deleteJson(route('api.settlement-claims.destroy', $claim))->assertForbidden();
    }
}
