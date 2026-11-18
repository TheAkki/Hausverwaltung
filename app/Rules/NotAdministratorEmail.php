<?php

namespace App\Rules;

use App\Models\User;
use App\Services\AdministratorAccount;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Die Adresse des Administrators (ADMIN_EMAIL) ist reserviert: Kein Konto darf sie
 * per Registrierung, Profil oder Benutzerverwaltung bekommen, sonst würde es zum
 * Administrator. Das Konto entsteht nur über "php artisan hv:admin".
 *
 * $owner ist das Konto, dessen Adresse geändert wird (null beim Anlegen). Hat es
 * die Adresse schon, ist es der Administrator selbst und darf sie behalten.
 */
class NotAdministratorEmail implements ValidationRule
{
    public function __construct(private readonly ?User $owner = null) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $account = app(AdministratorAccount::class);

        if (! $account->isAdministratorEmail(is_string($value) ? $value : null)) {
            return;
        }

        if ($this->owner !== null && $account->isAdministratorEmail($this->owner->email)) {
            return;
        }

        $fail(__('Diese E-Mail-Adresse ist für den Administrator reserviert.'));
    }
}
