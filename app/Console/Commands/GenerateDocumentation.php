<?php

namespace App\Console\Commands;

use App\Services\DocumentationGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Console command that writes the generated Bedienungsanleitung to public/docs/anleitung.html.
 */
#[Signature('docs:generate')]
#[Description('Generates the user-facing HTML Bedienungsanleitung from resources/docs/anleitung.md')]
class GenerateDocumentation extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DocumentationGenerator $generator): int
    {
        $path = public_path('docs/anleitung.html');

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $generator->renderHtml());
        File::copyDirectory((string) config('docs.screenshot_directory'), dirname($path).'/'.rtrim(DocumentationGenerator::SCREENSHOT_PREFIX, '/'));

        $this->info("Bedienungsanleitung generiert: {$path}");

        return self::SUCCESS;
    }
}
