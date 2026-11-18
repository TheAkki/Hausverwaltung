<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers CRUD for Benutzergruppen (Group): creation makes the creator admin/owner,
 * only admins can edit, only the owner can delete, and non-members cannot view.
 */
class GroupControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_index_lists_the_users_own_groups(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('groups.index'));

        $response->assertOk();
        $this->assertCount(1, $response->viewData('groups'));
    }

    public function test_the_create_form_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('groups.create'));

        $response->assertOk();
    }

    public function test_the_edit_form_can_be_rendered_by_an_admin(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $response = $this->actingAs($user)->get(route('groups.edit', $group));

        $response->assertOk();
    }

    public function test_creating_a_group_makes_the_creator_its_owner_and_admin(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('groups.store'), [
            'name' => 'Neue Gruppe',
            'description' => 'Eine Beschreibung',
        ]);

        $group = Group::where('name', 'Neue Gruppe')->first();
        $this->assertNotNull($group);
        $response->assertRedirect(route('groups.show', $group));

        $this->assertSame($user->id, $group->owner_id);
        $this->assertSame('admin', $group->users->firstWhere('id', $user->id)->pivot->role);
    }

    public function test_a_member_can_view_the_group(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $response = $this->actingAs($user)->get(route('groups.show', $group));

        $response->assertOk();
    }

    public function test_a_non_member_cannot_view_the_group(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->get(route('groups.show', $group));

        $response->assertForbidden();
    }

    public function test_an_admin_can_update_the_group(): void
    {
        $user = User::factory()->create();
        $group = $user->groups()->first();

        $response = $this->actingAs($user)->put(route('groups.update', $group), [
            'name' => 'Umbenannt',
            'description' => 'Neu',
        ]);

        $response->assertRedirect(route('groups.show', $group));
        $this->assertSame('Umbenannt', $group->fresh()->name);
    }

    public function test_a_non_admin_member_cannot_update_the_group(): void
    {
        $owner = User::factory()->create();
        $group = $owner->groups()->first();

        $regularMember = User::factory()->create();
        $group->users()->attach($regularMember->id, ['role' => 'user']);

        $response = $this->actingAs($regularMember)->put(route('groups.update', $group), [
            'name' => 'Hijacked',
        ]);

        $response->assertForbidden();
        $this->assertNotSame('Hijacked', $group->fresh()->name);
    }

    public function test_only_the_owner_can_delete_the_group(): void
    {
        $owner = User::factory()->create();
        $group = $owner->groups()->first();

        $otherAdmin = User::factory()->create();
        $group->users()->attach($otherAdmin->id, ['role' => 'admin']);

        // An admin who isn't the owner is forbidden from deleting.
        $this->actingAs($otherAdmin)->delete(route('groups.destroy', $group))->assertForbidden();
        $this->assertNotNull($group->fresh());

        $this->actingAs($owner)->delete(route('groups.destroy', $group))->assertRedirect(route('groups.index'));
        $this->assertNull(Group::find($group->id));
    }
}
