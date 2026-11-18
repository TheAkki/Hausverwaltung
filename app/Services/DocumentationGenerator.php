<?php

namespace App\Services;

use League\CommonMark\CommonMarkConverter;

/**
 * Renders the user-facing Bedienungsanleitung (HTML) from its Markdown source.
 */
class DocumentationGenerator
{
    private const SOURCE_PATH = 'docs/anleitung.md';

    /**
     * Renders the Markdown source under resources/docs into a complete,
     * self-contained HTML page.
     */
    public function renderHtml(): string
    {
        $markdown = file_get_contents(resource_path(self::SOURCE_PATH));

        $body = (new CommonMarkConverter())->convert($markdown)->getContent();

        return view('docs.layout', ['body' => $body])->render();
    }
}