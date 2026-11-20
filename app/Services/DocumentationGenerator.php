<?php

namespace App\Services;

use League\CommonMark\CommonMarkConverter;

/**
 * Renders the user-facing Bedienungsanleitung (HTML) from its Markdown source.
 */
class DocumentationGenerator
{
    private const SOURCE_PATH = 'docs/anleitung.md';

    /** Wie die Anleitung ihre Screenshots einbindet: ![Text](screenshots/name.png). */
    public const SCREENSHOT_PREFIX = 'screenshots/';

    /**
     * Renders the Markdown source under resources/docs into a complete,
     * self-contained HTML page.
     *
     * @param  string  $screenshotBase  Adresse der Screenshots: relativ für die statische
     *                                  Datei unter public/docs, sonst eine URL (/help)
     */
    public function renderHtml(string $screenshotBase = self::SCREENSHOT_PREFIX): string
    {
        $markdown = file_get_contents(resource_path(self::SOURCE_PATH));

        $body = (new CommonMarkConverter)->convert($markdown)->getContent();
        $body = str_replace('src="'.self::SCREENSHOT_PREFIX, 'src="'.rtrim($screenshotBase, '/').'/', $body);

        return view('docs.layout', ['body' => $body])->render();
    }
}
