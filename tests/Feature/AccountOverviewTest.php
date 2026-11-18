<?php

namespace Tests\Feature;

use App\Models\ManagementGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers access to the Kontoübersicht (per-Konto monthly balance overview) page.
 */
class AccountOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function makeManagementGroup(bool $kontosystemEnabled = true): ManagementGroup
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        return ManagementGroup::create([
            'description' => 'Test Group',
            'user_group_id' => $group->id,
            'kontosystem_enabled' => $kontosystemEnabled,
        ]);
    }

    public function test_the_overview_page_is_not_found_when_the_kontosystem_is_disabled(): void
    {
        $managementGroup = $this->makeManagementGroup(kontosystemEnabled: false);
        $user = $managementGroup->userGroup->users()->first();

        $response = $this->actingAs($user)->get(route('account-overview.show', $managementGroup));

        $response->assertNotFound();
    }

    public function test_the_overview_page_lists_each_konto_with_its_balances(): void
    {
        $managementGroup = $this->makeManagementGroup();
        $user = $managementGroup->userGroup->users()->first();
        $managementGroup->accounts()->create(['name' => 'Girokonto', 'initial_balance' => 1000]);

        $response = $this->actingAs($user)->get(route('account-overview.show', $managementGroup, ['year' => 2026]));

        $response->assertOk();
        $response->assertSee('Girokonto');
    }

    public function test_a_user_outside_the_group_cannot_view_its_overview(): void
    {
        $managementGroup = $this->makeManagementGroup();
        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)->get(route('account-overview.show', $managementGroup));

        $response->assertForbidden();
    }
}
