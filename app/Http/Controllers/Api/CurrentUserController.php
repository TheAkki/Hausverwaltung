<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Eigenschaften des angemeldeten Benutzers (Konzept.md "## API": "Benutzer
 * Eigenschaften auslesen – Name der persönlichen Gruppe"). Ein API-Client muss den
 * Namen seiner persönlichen Gruppe ("Gruppe von <Name>") so nicht erraten; er kann
 * sich durch eine Umbenennung des Benutzers oder der Gruppe ändern.
 */
class CurrentUserController extends Controller
{
    /** Felder der persönlichen Gruppe in der Antwort. */
    private const PERSONAL_GROUP_FIELDS = ['id', 'name'];

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            ...$user->toArray(),
            'personal_group' => $user->personalGroup()?->only(self::PERSONAL_GROUP_FIELDS),
        ]);
    }
}
