<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

/**
 * REST-API für Benutzer, gespiegelt von UserController: nur der Administrator legt
 * Benutzer an und sieht alle; jeder darf sein eigenes Konto sehen und ändern (siehe
 * UserPolicy). Löschen gibt es nicht.
 */
class UserController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct()
    {
        $this->authorizeResource(User::class, 'user');
    }

    /**
     * Liste aller Benutzer; mit "email" genau der Benutzer mit dieser Adresse (z. B. um
     * vor dem Anlegen zu prüfen, ob es ihn schon gibt).
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::orderBy('name');

        if ($request->filled('email')) {
            $query->where('email', strtolower((string) $request->input('email')));
        }

        return response()->json($query->paginate(self::PER_PAGE)->withQueryString());
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['email' => strtolower((string) $request->input('email'))]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json($user->load('groups'), 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json($user->load('groups'));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $request->merge(['email' => strtolower((string) $request->input('email'))]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email,'.$user->id],
        ]);

        $user->update($validated);

        return response()->json($user);
    }
}
