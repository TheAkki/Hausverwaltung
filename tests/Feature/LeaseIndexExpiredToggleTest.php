<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\ManagementGroup;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaseIndexExpiredToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_leases_are_hidden_by_default_and_shown_via_toggle(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);
        $rentalUnit = $managementUnit->rentalUnits()->create([
            'type' => 'Wohnung', 'size' => 50, 'is_relevant_for_billing' => true,
        ]);
        $tenant = Tenant::create(['first_name' => 'Alt', 'last_name' => 'Mieterus', 'street' => 'A', 'postal_code' => '1', 'city' => 'B']);
        $expiredLease = Lease::create([
            'rental_unit_id' => $rentalUnit->id, 'start_date' => '2020-01-01', 'end_date' => '2021-01-01',
            'rent_adjustment_type' => 'fixed', 'cold_rent' => 500,
        ]);
        $expiredLease->tenants()->attach($tenant->id);

        $response = $this->actingAs($user)->get(route('leases.index'));
        $response->assertOk();
        $response->assertDontSee('Mieterus');

        $response = $this->actingAs($user)->get(route('leases.index', ['show_expired' => 1]));
        $response->assertOk();
        $response->assertSee('Mieterus');
    }
}
