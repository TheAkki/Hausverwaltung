<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AdministratorAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Legt das Konto des Administrators (ADMIN_EMAIL) mit einem zufälligen
 * Startpasswort an. Erneut aufgerufen ändert der Befehl nichts; mit
 * --reset-password erhält ein vorhandenes Konto ein neues Startpasswort und alle
 * bisherigen Zugänge werden beendet.
 */
#[Signature('hv:admin {--reset-password : Neues Startpasswort für ein vorhandenes Administrator-Konto}')]
#[Description('Legt das Administrator-Konto (ADMIN_EMAIL) an oder setzt sein Passwort zurück')]
class CreateAdministrator extends Command
{
    public function handle(AdministratorAccount $account): int
    {
        $problem = $account->configurationProblem();

        if ($problem !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $user = $account->find();

        if ($user === null) {
            return $this->create($account);
        }

        if (! $this->option('reset-password')) {
            $this->info("Administrator existiert bereits ({$user->email}). Nichts geändert.");
            $this->line('Passwort vergessen? Erneut aufrufen mit --reset-password.');

            return self::SUCCESS;
        }

        return $this->reset($account, $user);
    }

    private function create(AdministratorAccount $account): int
    {
        $hadOtherUsers = User::exists();

        [$user, $password] = $account->create();

        $this->info("Administrator angelegt: {$user->email}");
        $this->showPassword($password);

        if ($hadOtherUsers) {
            $this->line('Hinweis: Bereits vorhandene Konten bleiben normale Benutzer – auch ein früheres '
                .'Administrator-Konto, falls ADMIN_EMAIL geändert wurde.');
        }

        return self::SUCCESS;
    }

    private function reset(AdministratorAccount $account, User $user): int
    {
        $password = $account->resetPassword($user);

        $this->info("Neues Startpasswort für {$user->email} gesetzt.");
        $this->line('API-Tokens und Anmeldungen dieses Kontos wurden beendet.');
        $this->showPassword($password);

        return self::SUCCESS;
    }

    private function showPassword(string $password): void
    {
        $this->newLine();
        $this->line("  Startpasswort: {$password}");
        $this->newLine();
        $this->line('Es wird nur jetzt angezeigt und muss beim ersten Login geändert werden.');
        $this->line('Anmelden unter: '.route('login'));
    }
}
