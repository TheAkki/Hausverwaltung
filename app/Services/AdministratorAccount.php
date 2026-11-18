<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Das Konto des Administrators: der Benutzer mit der E-Mail-Adresse aus
 * config('app.admin_email') (ADMIN_EMAIL, siehe User::isAdministrator()).
 *
 * Angelegt wird es nur über den Befehl "php artisan hv:admin" – mit einem
 * zufälligen Startpasswort, das beim ersten Login geändert werden muss. Über
 * Registrierung, Profil oder Benutzerverwaltung kann kein Konto diese Adresse
 * bekommen (Regel NotAdministratorEmail).
 */
class AdministratorAccount
{
    /**
     * Länge des zufälligen Startpassworts. Es besteht nur aus Buchstaben und
     * Ziffern, damit es sich problemlos abtippen und in der Shell kopieren lässt.
     */
    public const PASSWORD_LENGTH = 20;

    /** Name des neu angelegten Kontos (änderbar im Profil). */
    public const ACCOUNT_NAME = 'Administrator';

    /**
     * Für Beispiele reservierte Domains (RFC 2606). Eine Adresse dort ist ein
     * Platzhalter und taugt nicht für den Administrator (gleiche Prüfung wie in
     * docker/entrypoint.sh).
     */
    private const PLACEHOLDER_DOMAINS = ['example.com', 'example.org', 'example.net'];

    /**
     * Die konfigurierte Adresse des Administrators, wie sie gespeichert wird
     * (klein geschrieben, ohne Leerzeichen am Rand).
     */
    public function email(): string
    {
        return strtolower(trim((string) config('app.admin_email')));
    }

    /**
     * Ob $email die Adresse des Administrators ist (Groß-/Kleinschreibung egal).
     */
    public function isAdministratorEmail(?string $email): bool
    {
        $administrator = $this->email();

        return $administrator !== '' && strtolower(trim((string) $email)) === $administrator;
    }

    /**
     * Grund, warum die konfigurierte Adresse nicht verwendet werden kann, oder
     * null, wenn sie in Ordnung ist.
     */
    public function configurationProblem(): ?string
    {
        $email = $this->email();

        if ($email === '') {
            return 'ADMIN_EMAIL ist nicht gesetzt.';
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return "ADMIN_EMAIL ist keine gültige E-Mail-Adresse: {$email}";
        }

        if (in_array(Str::after($email, '@'), self::PLACEHOLDER_DOMAINS, true)) {
            return "ADMIN_EMAIL ist ein Platzhalter ({$email}). Bitte die eigene Adresse eintragen.";
        }

        return null;
    }

    public function find(): ?User
    {
        return User::where('email', $this->email())->first();
    }

    /**
     * Legt das Konto mit einem zufälligen Startpasswort an.
     *
     * @return array{0: User, 1: string} Konto und Startpasswort im Klartext
     */
    public function create(): array
    {
        $password = $this->newPassword();

        $user = new User;
        $user->forceFill([
            'name' => self::ACCOUNT_NAME,
            'email' => $this->email(),
            'password' => Hash::make($password),
            'must_change_password' => true,
        ])->save();

        return [$user, $password];
    }

    /**
     * Setzt ein neues zufälliges Startpasswort und beendet alle bestehenden
     * Zugänge des Kontos (API-Tokens, Sitzungen, "Angemeldet bleiben").
     *
     * @return string das neue Startpasswort im Klartext
     */
    public function resetPassword(User $user): string
    {
        $password = $this->newPassword();

        $user->forceFill([
            'password' => Hash::make($password),
            'must_change_password' => true,
            'remember_token' => Str::random(60),
        ])->save();

        $this->revokeAccess($user);

        return $password;
    }

    private function newPassword(): string
    {
        return Str::password(self::PASSWORD_LENGTH, symbols: false);
    }

    private function revokeAccess(User $user): void
    {
        $user->tokens()->delete();

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table'))
                ->where('user_id', $user->id)
                ->delete();
        }
    }
}
