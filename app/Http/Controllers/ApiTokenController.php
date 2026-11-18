<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Persönliche API-Tokens im Profil: Ein Script (z. B. scripts/api-client) meldet sich
 * damit an, ohne das Passwort zu kennen. Der Token wird nur direkt nach dem Erzeugen
 * einmal angezeigt; danach ist nur noch sein Name sichtbar und er kann widerrufen
 * werden.
 */
class ApiTokenController extends Controller
{
    /**
     * Session-Schlüssel, unter dem der neue Klartext-Token einmalig angezeigt wird.
     */
    public const SESSION_PLAIN_TOKEN = 'api-token-plain';

    public const STATUS_CREATED = 'api-token-created';

    public const STATUS_REVOKED = 'api-token-revoked';

    private const NAME_MAX_LENGTH = 255;

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('apiToken', [
            'token_name' => ['required', 'string', 'max:'.self::NAME_MAX_LENGTH],
        ]);

        $token = $request->user()->createToken($validated['token_name']);

        return redirect()->route('profile.edit')
            ->with('status', self::STATUS_CREATED)
            ->with(self::SESSION_PLAIN_TOKEN, $token->plainTextToken);
    }

    public function destroy(Request $request, int $tokenId): RedirectResponse
    {
        $request->user()->tokens()->whereKey($tokenId)->firstOrFail()->delete();

        return redirect()->route('profile.edit')->with('status', self::STATUS_REVOKED);
    }
}
