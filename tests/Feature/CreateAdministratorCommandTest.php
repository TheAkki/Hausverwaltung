<?php

// KI-generiert

namespace Tests\Feature;

use App\Models\User;
use App\Services\AdministratorAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * "php artisan hv:admin": legt den Administrator (ADMIN_EMAIL) mit zufälligem
 * Startpasswort an; ein erneuter Aufruf ändert nichts, --reset-password vergibt ein
 * neues Startpasswort und beendet alle bisherigen Zugänge.
 */
class CreateAdministratorCommandTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_EMAIL = 'chefin@hausverwaltung.test';

    private const SESSION_TABLE = 'sessions';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.admin_email' => self::ADMIN_EMAIL]);
    }

    /**
     * Führt den Befehl aus und liefert Exit-Code und Ausgabe.
     *
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: string}
     */
    private function runCommand(array $options = []): array
    {
        $exitCode = Artisan::call('hv:admin', $options);

        return [$exitCode, Artisan::output()];
    }

    private function passwordFrom(string $output): string
    {
        $this->assertMatchesRegularExpression('/Startpasswort: (\S+)/', $output);
        preg_match('/Startpasswort: (\S+)/', $output, $match);

        return $match[1];
    }

    // KI-generiert
    public function test_the_first_call_creates_the_administrator_with_a_random_start_password(): void
    {
        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $admin = User::where('email', self::ADMIN_EMAIL)->firstOrFail();
        $password = $this->passwordFrom($output);
        $this->assertSame(AdministratorAccount::PASSWORD_LENGTH, strlen($password));
        $this->assertTrue(ctype_alnum($password), 'Startpasswort nur aus Buchstaben und Ziffern');
        $this->assertTrue(Hash::check($password, $admin->password));
        $this->assertTrue($admin->must_change_password);
        $this->assertTrue($admin->isAdministrator());
        $this->assertSame(AdministratorAccount::ACCOUNT_NAME, $admin->name);
        $this->assertStringContainsString(route('login'), $output);
        $this->assertStringNotContainsString('Bereits vorhandene Konten', $output);
    }

    // KI-generiert
    public function test_the_address_is_stored_in_lower_case(): void
    {
        config(['app.admin_email' => '  Chefin@Hausverwaltung.TEST ']);

        $this->runCommand();

        $this->assertTrue(User::where('email', self::ADMIN_EMAIL)->exists());
    }

    // KI-generiert
    public function test_a_second_call_changes_nothing(): void
    {
        [, $firstOutput] = $this->runCommand();
        $hash = User::where('email', self::ADMIN_EMAIL)->value('password');

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('existiert bereits', $output);
        $this->assertStringContainsString('--reset-password', $output);
        $this->assertStringNotContainsString('Startpasswort:', $output);
        $this->assertSame($hash, User::where('email', self::ADMIN_EMAIL)->value('password'));
        $this->assertSame(1, User::count());
        $this->assertNotSame('', $this->passwordFrom($firstOutput));
    }

    // KI-generiert
    public function test_an_existing_admin_who_already_chose_a_password_keeps_it(): void
    {
        $admin = User::factory()->create(['email' => self::ADMIN_EMAIL, 'password' => Hash::make('Mein-Eigenes-1')]);

        $this->runCommand();

        $admin->refresh();
        $this->assertTrue(Hash::check('Mein-Eigenes-1', $admin->password));
        $this->assertFalse((bool) $admin->must_change_password);
    }

    // KI-generiert
    public function test_reset_password_sets_a_new_start_password_and_ends_all_access(): void
    {
        $admin = User::factory()->create([
            'email' => self::ADMIN_EMAIL,
            'password' => Hash::make('Altes-Passwort-1'),
            'remember_token' => 'alter-token',
        ]);
        $admin->createToken('script');
        $other = User::factory()->create();
        $other->createToken('fremd');
        DB::table(self::SESSION_TABLE)->insert([
            ['id' => 'admin-session', 'user_id' => $admin->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'other-session', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()],
        ]);
        config(['session.driver' => 'database']);

        [$exitCode, $output] = $this->runCommand(['--reset-password' => true]);

        $this->assertSame(0, $exitCode);
        $admin->refresh();
        $password = $this->passwordFrom($output);
        $this->assertTrue(Hash::check($password, $admin->password));
        $this->assertFalse(Hash::check('Altes-Passwort-1', $admin->password));
        $this->assertTrue($admin->must_change_password);
        $this->assertNotSame('alter-token', $admin->remember_token);
        $this->assertSame(0, $admin->tokens()->count());
        $this->assertSame(1, $other->tokens()->count());
        $this->assertSame(['other-session'], DB::table(self::SESSION_TABLE)->pluck('id')->all());
        $this->assertStringContainsString('beendet', $output);
    }

    // KI-generiert
    public function test_reset_keeps_sessions_of_other_drivers_untouched(): void
    {
        $admin = User::factory()->create(['email' => self::ADMIN_EMAIL]);
        DB::table(self::SESSION_TABLE)->insert(['id' => 's', 'user_id' => $admin->id, 'payload' => '', 'last_activity' => time()]);
        config(['session.driver' => 'file']);

        [$exitCode] = $this->runCommand(['--reset-password' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, DB::table(self::SESSION_TABLE)->count());
    }

    // KI-generiert
    public function test_reset_password_without_an_account_creates_it(): void
    {
        [$exitCode, $output] = $this->runCommand(['--reset-password' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Administrator angelegt', $output);
        $this->assertTrue(User::where('email', self::ADMIN_EMAIL)->exists());
    }

    // KI-generiert
    public function test_a_changed_admin_email_creates_a_new_admin_and_the_old_account_becomes_a_normal_user(): void
    {
        $old = User::factory()->create(['email' => 'frueher@hausverwaltung.test']);
        config(['app.admin_email' => 'frueher@hausverwaltung.test']);
        $this->assertTrue($old->isAdministrator());

        config(['app.admin_email' => self::ADMIN_EMAIL]);
        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Bereits vorhandene Konten bleiben normale Benutzer', $output);
        $this->assertFalse($old->fresh()->isAdministrator());
        $this->assertTrue(User::where('email', self::ADMIN_EMAIL)->firstOrFail()->isAdministrator());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unusableAdminEmails(): array
    {
        return [
            'leer' => ['', 'nicht gesetzt'],
            'nur Leerzeichen' => ['   ', 'nicht gesetzt'],
            'keine Adresse' => ['admin', 'keine gültige E-Mail-Adresse'],
            'Platzhalter .com' => ['admin@example.com', 'Platzhalter'],
            'Platzhalter .org' => ['admin@example.org', 'Platzhalter'],
            'Platzhalter .net' => ['Admin@Example.NET', 'Platzhalter'],
        ];
    }

    // KI-generiert
    #[DataProvider('unusableAdminEmails')]
    public function test_an_unusable_admin_email_is_rejected_and_nothing_is_created(string $email, string $message): void
    {
        config(['app.admin_email' => $email]);

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString($message, $output);
        $this->assertSame(0, User::count());
    }
}
