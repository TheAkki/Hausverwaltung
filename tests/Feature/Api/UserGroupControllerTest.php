<?php

namespace Tests\Feature\Api;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\CreatesApiFixtures;
use Tests\TestCase;

// KI-generiert
class UserGroupControllerTest extends TestCase
{
    use CreatesApiFixtures, RefreshDatabase;

    private const PASSWORD = 'Sicheres-Passwort-1';

    private function apiAdministrator(): User
    {
        $admin = User::factory()->create(['email' => config('app.admin_email')]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_users_can_be_listed_created_shown_and_updated(): void
    {
        $this->apiAdministrator();

        $created = $this->postJson(route('api.users.store'), [
            'name' => 'Neu', 'email' => 'NEU@example.com', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ])->assertCreated()->assertJsonPath('email', 'neu@example.com')->json();

        // Der UserObserver legt die persönliche Gruppe an – sie ist in der Antwort enthalten.
        $this->assertSame('Gruppe von Neu', $created['groups'][0]['name']);

        $this->getJson(route('api.users.index'))->assertOk()->assertJsonFragment(['email' => 'neu@example.com']);
        $this->getJson(route('api.users.show', $created['id']))->assertOk()->assertJsonPath('name', 'Neu');
        $this->putJson(route('api.users.update', $created['id']), ['name' => 'Umbenannt', 'email' => 'neu@example.com'])
            ->assertOk()->assertJsonPath('name', 'Umbenannt');
    }

    public function test_creating_a_user_requires_a_confirmed_password_and_unique_email(): void
    {
        $existing = $this->apiAdministrator();

        $this->postJson(route('api.users.store'), [
            'name' => 'X', 'email' => $existing->email, 'password' => self::PASSWORD, 'password_confirmation' => 'anders',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_the_user_endpoints_require_authentication(): void
    {
        $this->getJson(route('api.users.index'))->assertUnauthorized();
    }

    public function test_a_created_group_is_owned_and_administered_by_its_creator(): void
    {
        $user = $this->apiUser();

        $group = $this->postJson(route('api.groups.store'), ['name' => 'Sharegroup', 'description' => 'geteilt'])
            ->assertCreated()->json();

        $this->assertSame($user->id, $group['owner_id']);
        $this->assertSame('admin', Group::find($group['id'])->users()->first()->pivot->role);
        $this->getJson(route('api.groups.index'))->assertOk()->assertJsonCount(2);
        $this->getJson(route('api.groups.show', $group['id']))->assertOk()->assertJsonPath('owner.id', $user->id);
        $this->putJson(route('api.groups.update', $group['id']), ['name' => 'Neu'])->assertOk()->assertJsonPath('name', 'Neu');
        $this->deleteJson(route('api.groups.destroy', $group['id']))->assertNoContent();
        $this->assertNull(Group::find($group['id']));
    }

    public function test_members_can_be_added_promoted_and_removed(): void
    {
        $user = $this->apiUser();
        $member = User::factory()->create();
        $group = $this->groupOf($user);

        $this->postJson(route('api.groups.members.store', $group), ['email' => strtoupper($member->email)])->assertCreated();
        // Erneutes Hinzufügen ist folgenlos (keine doppelte Mitgliedschaft).
        $this->postJson(route('api.groups.members.store', $group), ['email' => $member->email])->assertCreated();
        $this->assertSame('user', $group->users()->where('users.id', $member->id)->first()->pivot->role);

        $this->patchJson(route('api.groups.members.update', [$group, $member]), ['role' => 'admin'])->assertOk();
        $this->assertSame('admin', $group->users()->where('users.id', $member->id)->first()->pivot->role);

        $this->patchJson(route('api.groups.members.update', [$group, $member]), ['role' => 'chef'])->assertUnprocessable();

        $this->deleteJson(route('api.groups.members.destroy', [$group, $member]))->assertNoContent();
        $this->assertFalse($group->users()->where('users.id', $member->id)->exists());
    }

    public function test_only_group_admins_may_manage_members(): void
    {
        $owner = User::factory()->create();
        $intruder = $this->apiUser();
        $group = $this->groupOf($owner);

        $this->postJson(route('api.groups.members.store', $group), ['email' => $intruder->email])->assertForbidden();
        $this->patchJson(route('api.groups.members.update', [$group, $owner]), ['role' => 'user'])->assertForbidden();
        $this->deleteJson(route('api.groups.members.destroy', [$group, $owner]))->assertForbidden();
        $this->getJson(route('api.groups.show', $group))->assertForbidden();
    }

    // KI-generiert
    public function test_regular_users_only_see_and_change_their_own_account(): void
    {
        $user = $this->apiUser();
        $other = User::factory()->create();

        $this->getJson(route('api.users.index'))->assertForbidden();
        $this->postJson(route('api.users.store'), ['name' => 'X'])->assertForbidden();
        $this->getJson(route('api.users.show', $other))->assertForbidden();
        $this->putJson(route('api.users.update', $other), ['name' => 'Gekapert', 'email' => 'boese@example.com'])->assertForbidden();
        $this->getJson(route('api.users.show', $user))->assertOk();
        $this->putJson(route('api.users.update', $user), ['name' => 'Selbst', 'email' => $user->email])->assertOk();
    }

    // KI-generiert
    public function test_the_administrator_can_look_users_up_by_email(): void
    {
        $this->apiAdministrator();
        User::factory()->create(['email' => 'gesucht@example.com']);
        User::factory()->create();

        $this->getJson(route('api.users.index', ['email' => 'Gesucht@Example.com']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'gesucht@example.com');
    }

    // KI-generiert
    public function test_the_administrator_sets_up_groups_and_members_for_other_users(): void
    {
        $this->apiAdministrator();
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $group = $this->postJson(route('api.groups.store'), ['name' => 'Geteilt', 'owner_id' => $owner->id])->assertCreated()->json();

        $this->assertSame($owner->id, $group['owner_id']);
        $this->assertSame([$owner->id], array_column($group['users'], 'id'));
        $this->postJson(route('api.groups.members.store', $group['id']), ['email' => $member->email])->assertCreated();
        $this->patchJson(route('api.groups.members.update', [$group['id'], $member]), ['role' => 'admin'])->assertOk();
        $this->getJson(route('api.groups.show', $group['id']))->assertOk();
    }

    // KI-generiert
    public function test_regular_users_cannot_create_groups_for_others(): void
    {
        $this->apiUser();
        $other = User::factory()->create();

        $this->postJson(route('api.groups.store'), ['name' => 'Fremd', 'owner_id' => $other->id])->assertForbidden();
        $this->postJson(route('api.groups.store'), ['name' => 'Fremd', 'owner_id' => 999999])->assertUnprocessable();
    }
}
