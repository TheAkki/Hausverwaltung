<?php

// KI-generiert

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Die Adresse aus ADMIN_EMAIL ist reserviert: Kein Konto darf sie über
 * Registrierung, Profil, Benutzerverwaltung (Web) oder API bekommen – sonst würde es
 * zum Administrator. Der Administrator selbst darf seine Adresse behalten.
 */
class AdministratorEmailReservedTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_EMAIL = 'chefin@hausverwaltung.test';

    private const PASSWORD = 'Sicheres-Passwort-1';

    private const RESERVED_MESSAGE = 'Diese E-Mail-Adresse ist für den Administrator reserviert.';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.admin_email' => self::ADMIN_EMAIL]);
    }

    private function administrator(): User
    {
        return User::factory()->create(['email' => self::ADMIN_EMAIL]);
    }

    // KI-generiert
    public function test_nobody_can_register_with_the_admin_email_in_any_spelling(): void
    {
        foreach ([self::ADMIN_EMAIL, 'Chefin@Hausverwaltung.TEST'] as $email) {
            $this->post('/register', [
                'name' => 'Eindringling', 'email' => $email,
                'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
            ])->assertSessionHasErrors(['email' => self::RESERVED_MESSAGE]);
        }

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    // KI-generiert
    public function test_registering_with_another_email_still_works(): void
    {
        $this->post('/register', [
            'name' => 'Anna', 'email' => 'anna@hausverwaltung.test',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticated();
    }

    // KI-generiert
    public function test_a_user_cannot_take_over_the_admin_email_in_the_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/profile', ['name' => $user->name, 'email' => self::ADMIN_EMAIL])
            ->assertSessionHasErrors('email');

        $this->assertNotSame(self::ADMIN_EMAIL, $user->fresh()->email);
    }

    // KI-generiert
    public function test_the_administrator_keeps_the_own_email_when_editing_the_profile(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)->patch('/profile', ['name' => 'Neuer Name', 'email' => self::ADMIN_EMAIL])
            ->assertSessionHasNoErrors();

        $this->assertSame('Neuer Name', $admin->fresh()->name);
    }

    // KI-generiert
    public function test_the_admin_email_cannot_be_given_to_another_account_in_the_user_administration(): void
    {
        $admin = $this->administrator();
        $other = User::factory()->create();

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Zweiter', 'email' => self::ADMIN_EMAIL,
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->actingAs($admin)->put(route('users.update', $other), ['name' => $other->name, 'email' => self::ADMIN_EMAIL])
            ->assertSessionHasErrors('email');

        $this->assertNotSame(self::ADMIN_EMAIL, $other->fresh()->email);
    }

    // KI-generiert
    public function test_while_no_admin_exists_a_user_cannot_rename_the_own_account_to_the_admin_email(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('users.update', $user), ['name' => $user->name, 'email' => self::ADMIN_EMAIL])
            ->assertSessionHasErrors('email');

        Sanctum::actingAs($user);
        $this->putJson(route('api.users.update', $user), ['name' => $user->name, 'email' => self::ADMIN_EMAIL])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', self::RESERVED_MESSAGE);

        $this->assertFalse($user->fresh()->isAdministrator());
    }

    // KI-generiert
    public function test_the_api_rejects_the_admin_email_for_new_accounts_but_lets_the_admin_keep_it(): void
    {
        $admin = $this->administrator();
        Sanctum::actingAs($admin);

        $this->postJson(route('api.users.store'), [
            'name' => 'Zweiter', 'email' => self::ADMIN_EMAIL,
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->putJson(route('api.users.update', $admin), ['name' => 'Chefin', 'email' => self::ADMIN_EMAIL])
            ->assertOk()->assertJsonPath('name', 'Chefin');
    }

    // KI-generiert
    public function test_the_message_is_translated(): void
    {
        app()->setLocale('en');

        $this->post('/register', [
            'name' => 'Eindringling', 'email' => self::ADMIN_EMAIL,
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ])->assertSessionHasErrors(['email' => 'This email address is reserved for the administrator.']);
    }
}
