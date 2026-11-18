<?php

namespace Tests\Unit;

use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\User;
use App\Rules\ValidLeaseCoupling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers ValidLeaseCoupling's own edge cases not reachable through LeaseController's
 * HTTP flow (see LeaseControllerTest for the rest): "rental_unit_id" is always
 * validated with "exists:rental_units,id" before this rule ever runs, so its defensive
 * null-check for a since-deleted/nonexistent Mieteinheit can only be exercised by
 * calling the rule directly.
 */
class ValidLeaseCouplingTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_is_a_no_op_when_the_rental_unit_no_longer_exists(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create(['street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $rentalUnit = $managementUnit->rentalUnits()->create(['type' => 'Wohnung', 'is_relevant_for_billing' => true]);
        $targetLease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2026-01-01', 'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);

        $rule = new ValidLeaseCoupling(999999, null);
        $failed = false;

        $rule->validate('coupled_to_lease_id', $targetLease->id, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    /**
     * Reached via LeaseController::store() only in the (untestable via HTTP) race
     * condition where the referenced Mietvertrag is deleted between the request's two
     * validate() calls – the "coupled_to_lease_id" field's own "exists:leases,id" rule
     * already rejects a value that was never a real Mietvertrag to begin with.
     */
    public function test_fails_when_the_referenced_lease_does_not_exist(): void
    {
        $rule = new ValidLeaseCoupling(1, null);
        $message = null;

        $rule->validate('coupled_to_lease_id', 999999, function ($msg) use (&$message) {
            $message = $msg;
        });

        $this->assertSame('Der referenzierte Mietvertrag wurde nicht gefunden.', $message);
    }
}
