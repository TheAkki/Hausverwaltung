<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Manages membership of Users within a Group: adding, role changes, and removal,
 * authorized against the group's own update permission.
 */
class GroupMembershipController extends Controller
{
    /**
     * Add a user to the group.
     */
    public function store(Request $request, Group $group): RedirectResponse
    {
        $this->authorize('update', $group);

        $request->merge(['email' => strtolower((string) $request->input('email'))]);

        $validated = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
        ]);

        $user = User::where('email', $validated['email'])->firstOrFail();

        if (! $group->users->contains($user->id)) {
            $group->users()->attach($user->id, ['role' => 'user']);
        }

        return redirect()->route('groups.show', $group);
    }

    /**
     * Update a member's role (promote/demote admin).
     */
    public function update(Request $request, Group $group, User $user): RedirectResponse
    {
        $this->authorize('update', $group);

        $validated = $request->validate([
            'role' => ['required', 'in:admin,user'],
        ]);

        $group->users()->updateExistingPivot($user->id, ['role' => $validated['role']]);

        return redirect()->route('groups.show', $group);
    }

    /**
     * Remove a user from the group.
     */
    public function destroy(Group $group, User $user): RedirectResponse
    {
        $this->authorize('update', $group);

        $group->users()->detach($user->id);

        return redirect()->route('groups.show', $group);
    }
}
