<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST-API-CRUD für Benutzergruppen, gespiegelt von GroupController: der
 * anlegende Benutzer wird Eigentümer und Admin der neuen Gruppe.
 */
class GroupController extends Controller
{
    private const ROLE_ADMIN = 'admin';

    public function __construct()
    {
        $this->authorizeResource(Group::class, 'group');
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($request->user()->groups()->withCount('users')->get());
    }

    /**
     * Legt eine Gruppe an. Der Administrator kann mit "owner_id" eine Gruppe für einen
     * anderen Benutzer anlegen (Einrichten von Konten und Gruppen); Eigentümer und
     * erster Admin ist dann dieser Benutzer.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate(['owner_id' => ['nullable', 'integer', 'exists:users,id']]);

        $ownerId = $request->filled('owner_id') ? $request->integer('owner_id') : $request->user()->id;
        abort_unless($ownerId === $request->user()->id || $request->user()->isAdministrator(), 403);

        $group = Group::create([...$this->validated($request), 'owner_id' => $ownerId]);
        $group->users()->attach($ownerId, ['role' => self::ROLE_ADMIN]);

        return response()->json($group->load('users'), 201);
    }

    public function show(Group $group): JsonResponse
    {
        return response()->json($group->load('users', 'owner'));
    }

    public function update(Request $request, Group $group): JsonResponse
    {
        $group->update($this->validated($request));

        return response()->json($group);
    }

    public function destroy(Group $group): JsonResponse
    {
        $group->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);
    }
}
