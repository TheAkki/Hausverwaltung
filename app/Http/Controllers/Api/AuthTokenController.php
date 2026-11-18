<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Issues/revokes Laravel Sanctum personal access tokens for the REST API
 * (Konzept.md "## API": "Ein- und Ausloggen") – separate from the session-based
 * web login (AuthenticatedSessionController), which an API client cannot use since
 * it has no browser cookie jar.
 */
class AuthTokenController extends Controller
{
    /**
     * Authenticates the given credentials (same rules/throttling as the web login,
     * see LoginRequest) and returns a new personal access token for them.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        /** @var User $user */
        $user = Auth::user();

        $token = $user->createToken((string) $request->string('device_name', 'api'));

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => $user,
        ], 201);
    }

    /**
     * Revokes the access token used to authenticate the current request.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(status: 204);
    }
}
