<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Bootstraps the default Admin account (see resources/docs/anleitung.md).
 */
class AdminUserSeeder extends Seeder
{
    /**
     * Creates the default Admin account with an empty password. The UserObserver
     * takes care of creating the owner group. must_change_password forces the
     * account to set a real password (via the ForcePasswordChange middleware)
     * right after the first login.
     */
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Admin', 'password' => Hash::make('')]
        );

        if ($user->wasRecentlyCreated) {
            $user->must_change_password = true;
            $user->save();
        }
    }
}
