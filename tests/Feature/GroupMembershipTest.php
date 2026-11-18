<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers adding/removing/promoting members of a Group, including the email
 * normalization needed for case-insensitive lookups (emails are stored lowercase).
 */
class GroupMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_can_be_added_by_email_even_with_mixed_casing(): void
    {
        $owner = User::factory()->create();
        $group = $owner->groups()->first();

        $newMember = User::factory()->create(['email' => 'vermieter@example.com']);

        $response = $this->actingAs($owner)->post(route('groups.members.store', $group), [
            'email' => 'Vermieter@Example.com',
        ]);

        $response->assertRedirect(route('groups.show', $group));
        $this->assertTrue($group->fresh()->users->contains($newMember->id));
    }

    public function test_adding_a_nonexistent_email_fails_validation(): void
    {
        $owner = User::factory()->create();
        $group = $owner->groups()->first();

        $response = $this->actingAs($owner)->post(route('groups.members.store', $group), [
            'email' => 'nobody@example.com',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_a_non_admin_cannot_add_members(): void
    {
        $owner = User::factory()->create();
        $group = $owner->groups()->first();

        $regularMember = User::factory()->create();
        $group->users()->attach($regularMember->id, ['role' => 'user']);

        $newMember = User::factory()->create(['email' => 'someone@example.com']);

        $response = $this->actingAs($regularMember)->post(route('groups.members.store', $group), [
            'email' => 'someone@example.com',
        ]);

        $response->assertForbidden();
        $this->assertFalse($group->fresh()->users->contains($newMember->id));
    }

    public function test_adding_a_member_who_is_already_in_the_group_is_a_no_op(): void
    {
        $owner = User::factory()->create();
        $group = $owner->groups()->first();

        $response = $this->actingAs($owner)->post(route('groups.members.store', $group), [
            'email' => $owner->email,
        ]);

        $response->assertRedirect(route('groups.show', $group));
        $this->assertSame(1, $group->fresh()->users()->count());
    }

    public function test_an_admin_can_promote_a_member_to_admin(): void
    {
        $owner = User::factory()->create();
        $group = $owner->groups()->first();
        $member = User::factory()->create();
        $group->users()->attach($member->id, ['role' => 'user']);

        $response = $this->actingAs($owner)->patch(route('groups.members.update', [$group, $member]), [
            'role' => 'admin',
        ]);

        $response->assertRedirect(route('groups.show', $group));
        $this->assertSame('admin', $group->users()->find($member->id)->pivot->role);
    }

    public function test_a_non_admin_cannot_change_a_members_role(): void
    {
        $owner = User::factory()->create();
        $group = $owner->groups()->first();
        $member = User::factory()->create();
        $group->users()->attach($member->id, ['role' => 'user']);

        $response = $this->actingAs($member)->patch(route('groups.members.update', [$group, $member]), [
            'role' => 'admin',
        ]);

        $response->assertForbidden();
        $this->assertSame('user', $group->users()->find($member->id)->pivot->role);
    }

    public function test_an_admin_can_remove_a_member(): void
    {
        $owner = User::factory()->create();
        $group = $owner->groups()->first();
        $member = User::factory()->create();
        $group->users()->attach($member->id, ['role' => 'user']);

        $response = $this->actingAs($owner)->delete(route('groups.members.destroy', [$group, $member]));

        $response->assertRedirect(route('groups.show', $group));
        $this->assertFalse($group->fresh()->users->contains($member->id));
    }

    public function test_a_non_admin_cannot_remove_a_member(): void
    {
        $owner = User::factory()->create();
        $group = $owner->groups()->first();
        $member = User::factory()->create();
        $group->users()->attach($member->id, ['role' => 'user']);

        $response = $this->actingAs($member)->delete(route('groups.members.destroy', [$group, $owner]));

        $response->assertForbidden();
        $this->assertTrue($group->fresh()->users->contains($owner->id));
    }
}
