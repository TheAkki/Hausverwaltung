<?php

namespace App\Http\Controllers;

use App\Services\DocumentationGenerator;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the auto-generated Bedienungsanleitung (user manual) and its screenshots.
 */
class HelpController extends Controller
{
    /** Erlaubte Dateinamen der Screenshots – kein Weg aus dem Verzeichnis heraus. */
    private const SCREENSHOT_PATTERN = '/\A[a-z0-9-]+\.png\z/';

    /**
     * Shows the Bedienungsanleitung, rendered fresh from its Markdown source
     * so it never drifts out of sync with resources/docs/anleitung.md.
     */
    public function show(DocumentationGenerator $generator): Response
    {
        $screenshotBase = Str::beforeLast(route('help.screenshot', ['file' => 'platzhalter.png']), '/');

        return response($generator->renderHtml($screenshotBase));
    }

    public function screenshot(string $file): BinaryFileResponse
    {
        $path = config('docs.screenshot_directory').'/'.$file;

        abort_unless(preg_match(self::SCREENSHOT_PATTERN, $file) === 1 && is_file($path), 404);

        return response()->file($path);
    }
}
