<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Serves the hand-maintained OpenAPI specification of the REST API (routes/api.php)
 * and an interactive Swagger UI for it.
 */
class ApiDocumentationController extends Controller
{
    public const SPEC_PATH = 'api/openapi.yaml';

    private const SPEC_CONTENT_TYPE = 'application/yaml';

    public function show(): View
    {
        return view('api-docs.show');
    }

    public function spec(): Response
    {
        return response(file_get_contents(resource_path(self::SPEC_PATH)), 200, [
            'Content-Type' => self::SPEC_CONTENT_TYPE,
        ]);
    }
}
