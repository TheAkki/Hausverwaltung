<?php

namespace App\Http\Controllers;

use App\Services\DocumentationGenerator;
use Illuminate\Http\Response;

/**
 * Serves the auto-generated Bedienungsanleitung (user manual).
 */
class HelpController extends Controller
{
    /**
     * Shows the Bedienungsanleitung, rendered fresh from its Markdown source
     * so it never drifts out of sync with resources/docs/anleitung.md.
     */
    public function show(DocumentationGenerator $generator): Response
    {
        return response($generator->renderHtml());
    }
}
