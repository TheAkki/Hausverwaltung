<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST-API für Gruppenmitgliedschaften, gespiegelt von GroupMembershipController:
 * Hinzufügen (per E-Mail, Rolle "user"), Rolle ändern und Entfernen – jeweils nur
 * für Admins der Gruppe.
 */
class GroupMembershipController extends Controller
{
    private const ROLE_USER = 'user';

    private const ROLES = ['admin', self::ROLE_USER];

    public function store(Request $request, Group $group): JsonResponse
    {
        $this->authorize('update', $group);

        $request->merge(['email' => strtolower((string) $request->input('email'))]);

        $validated = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
        ]);

        $user = User::where('email', $validated['email'])->firstOrFail();

        if (! $group->users->contains($user->id)) {
            $group->users()->attach($user->id, ['role' => self::ROLE_USER]);
        }

        return response()->json($group->load('users'), 201);
    }

    public function update(Request $request, Group $group, User $user): JsonResponse
    {
        $this->authorize('update', $group);

        $validated = $request->validate([
            'role' => ['required', 'in:'.implode(',', self::ROLES)],
        ]);

        $group->users()->updateExistingPivot($user->id, ['role' => $validated['role']]);

        return response()->json($group->load('users'));
    }

    public function destroy(Group $group, User $user): JsonResponse
    {
        $this->authorize('update', $group);

        $group->users()->detach($user->id);

        return response()->json(status: 204);
    }
}
