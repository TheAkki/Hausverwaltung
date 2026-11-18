<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Covers CRUD for the /users resource (index/create/store/show/edit/update). There is
 * no destroy route (a user cannot delete themselves or others, per Konzept.md).
 */
class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    // KI-generiert
    private function administrator(): User
    {
        return User::factory()->create(['email' => config('app.admin_email')]);
    }

    public function test_the_index_lists_all_users(): void
    {
        $user = $this->administrator();
        User::factory()->create(['name' => 'Other Person']);

        $response = $this->actingAs($user)->get(route('users.index'));

        $response->assertOk();
        $response->assertSee('Other Person');
    }

    public function test_a_user_can_be_created_with_a_hashed_password(): void
    {
        $user = $this->administrator();

        $response = $this->actingAs($user)->post(route('users.store'), [
            'name' => 'Neuer Nutzer',
            'email' => 'Neuer.Nutzer@Example.com',
            'password' => 'geheim1234',
            'password_confirmation' => 'geheim1234',
        ]);

        $newUser = User::where('name', 'Neuer Nutzer')->first();
        $this->assertNotNull($newUser);
        $response->assertRedirect(route('users.show', $newUser));

        // Email is normalized to lowercase.
        $this->assertSame('neuer.nutzer@example.com', $newUser->email);
        $this->assertTrue(Hash::check('geheim1234', $newUser->password));
    }

    public function test_creating_a_user_with_a_duplicate_email_fails_validation(): void
    {
        $user = $this->administrator();
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->actingAs($user)->post(route('users.store'), [
            'name' => 'Doppelt',
            'email' => 'Taken@Example.com',
            'password' => 'geheim1234',
            'password_confirmation' => 'geheim1234',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertSame(2, User::count());
    }

    public function test_creating_a_user_with_mismatched_password_confirmation_fails_validation(): void
    {
        $user = $this->administrator();

        $response = $this->actingAs($user)->post(route('users.store'), [
            'name' => 'Doppelt',
            'email' => 'unconfirmed@example.com',
            'password' => 'geheim1234',
            'password_confirmation' => 'anders1234',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertSame(1, User::count());
    }

    public function test_the_show_page_displays_the_users_groups(): void
    {
        $user = $this->administrator();
        $otherUser = User::factory()->create(['name' => 'Zu Zeigender Nutzer']);

        $response = $this->actingAs($user)->get(route('users.show', $otherUser));

        $response->assertOk();
        $response->assertSee('Zu Zeigender Nutzer');
    }

    public function test_a_users_name_and_email_can_be_updated(): void
    {
        $user = $this->administrator();
        $otherUser = User::factory()->create();

        $response = $this->actingAs($user)->put(route('users.update', $otherUser), [
            'name' => 'Neuer Name',
            'email' => 'Neue.Email@Example.com',
        ]);

        $response->assertRedirect(route('users.show', $otherUser));
        $this->assertSame('Neuer Name', $otherUser->fresh()->name);
        $this->assertSame('neue.email@example.com', $otherUser->fresh()->email);
    }

    public function test_updating_a_user_to_an_email_already_used_by_someone_else_fails_validation(): void
    {
        $user = $this->administrator();
        $otherUser = User::factory()->create();
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->actingAs($user)->put(route('users.update', $otherUser), [
            'name' => $otherUser->name,
            'email' => 'existing@example.com',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertNotSame('existing@example.com', $otherUser->fresh()->email);
    }

    public function test_there_is_no_route_to_delete_a_user(): void
    {
        $user = $this->administrator();
        $otherUser = User::factory()->create();

        $response = $this->actingAs($user)->delete("/users/{$otherUser->id}");

        $response->assertStatus(405);
        $this->assertNotNull($otherUser->fresh());
    }

    // KI-generiert
    public function test_regular_users_may_neither_list_nor_create_nor_edit_others(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->actingAs($user)->get(route('users.index'))->assertForbidden();
        $this->actingAs($user)->get(route('users.create'))->assertForbidden();
        $this->actingAs($user)->post(route('users.store'), ['name' => 'X'])->assertForbidden();
        $this->actingAs($user)->get(route('users.show', $otherUser))->assertForbidden();
        $this->actingAs($user)->put(route('users.update', $otherUser), ['name' => 'Gekapert', 'email' => 'boese@example.com'])->assertForbidden();
        $this->assertNotSame('boese@example.com', $otherUser->fresh()->email);
    }

    // KI-generiert
    public function test_regular_users_may_view_and_edit_their_own_account_and_do_not_see_the_menu_entry(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('users.show', $user))->assertOk()->assertDontSee('href="'.route('users.index').'"', false);
        $this->actingAs($user)->get(route('users.edit', $user))->assertOk();
        $this->actingAs($user)->put(route('users.update', $user), ['name' => 'Selbst', 'email' => $user->email])
            ->assertRedirect(route('users.show', $user));
    }

    // KI-generiert
    public function test_the_administrator_sees_the_menu_entry_and_the_create_form(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)->get(route('dashboard'))->assertSee('href="'.route('users.index').'"', false);
        $this->actingAs($admin)->get(route('users.create'))->assertOk();
    }
}
