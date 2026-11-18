<?php

namespace App\Policies;

use App\Models\User;

/**
 * Benutzerkonten verwaltet der Administrator (Konzept.md "## Administation"): nur er
 * darf Benutzer auflisten und anlegen. Jeder Benutzer darf sein eigenes Konto sehen
 * und ändern, fremde Konten nur der Administrator.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdministrator();
    }

    public function view(User $user, User $model): bool
    {
        return $user->is($model) || $user->isAdministrator();
    }

    public function create(User $user): bool
    {
        return $user->isAdministrator();
    }

    public function update(User $user, User $model): bool
    {
        return $user->is($model) || $user->isAdministrator();
    }
}
