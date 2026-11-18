<?php

namespace App\Observers;

use App\Models\Group;
use App\Models\User;

/**
 * Reacts to User "created" events by provisioning a personal Group with the new
 * user as its admin member.
 */
class UserObserver
{
    /**
     * Handle the User "created" event.
     */
    public function created(User $user): void
    {
        $group = Group::create([
            'name' => "Gruppe von {$user->name}",
            'owner_id' => $user->id,
        ]);

        $group->users()->attach($user->id, ['role' => 'admin']);
    }
}
