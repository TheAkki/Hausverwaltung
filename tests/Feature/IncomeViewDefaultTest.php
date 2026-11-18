<?php

namespace Tests\Feature;

use App\Models\ManagementGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomeViewDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tenant_grouping_is_the_default_income_view(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'MG', 'user_group_id' => $group->id]);
        $managementUnit = $managementGroup->managementUnits()->create([
            'street' => 'Teststr 1', 'postal_code' => '11111', 'city' => 'Teststadt',
        ]);

        $response = $this->actingAs($user)->get(route('management-units.show', $managementUnit));

        $response->assertOk();
        // The switcher marks the active view with the "font-semibold text-gray-900"
        // class; without an explicit income_view query param it should land on "Mieter".
        $this->assertMatchesRegularExpression(
            '/class="font-semibold text-gray-900"\s*>Mieter</',
            $response->getContent()
        );
    }
}
