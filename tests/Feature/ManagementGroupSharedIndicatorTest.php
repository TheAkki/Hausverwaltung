<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\ManagementGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the "geteilt" (shared) indicator on the Verwaltungsgruppen index page, shown
 * whenever a Verwaltungsgruppe's Benutzergruppe has more than one member.
 */
class ManagementGroupSharedIndicatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_management_group_owned_by_a_single_member_group_is_not_marked_as_shared(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();
        $managementGroup = ManagementGroup::create(['description' => 'Solo VG', 'user_group_id' => $group->id]);

        $this->assertFalse($managementGroup->isShared());

        $response = $this->actingAs($user)->get(route('management-groups.index'));
        $response->assertOk();
        $response->assertDontSee('Geteilt mit mehreren Benutzern');
    }

    public function test_a_management_group_owned_by_a_multi_member_group_is_marked_as_shared(): void
    {
        $owner = User::factory()->create();
        $group = $owner->groups()->first();

        $otherMember = User::factory()->create();
        $group->users()->attach($otherMember->id, ['role' => 'user']);

        $managementGroup = ManagementGroup::create(['description' => 'Shared VG', 'user_group_id' => $group->id]);

        $this->assertTrue($managementGroup->isShared());

        $response = $this->actingAs($owner)->get(route('management-groups.index'));
        $response->assertOk();
        $response->assertSee('Geteilt mit mehreren Benutzern');
    }
}
