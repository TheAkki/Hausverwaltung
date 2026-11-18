<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class GenerateApiDocumentationTest extends TestCase
{
    public function test_it_fails_gracefully_when_doxygen_is_not_installed(): void
    {
        $originalPath = getenv('PATH');
        putenv('PATH=/nonexistent-path-without-doxygen');

        try {
            $this->artisan('docs:api')
                ->expectsOutputToContain('doxygen ist nicht installiert')
                ->assertExitCode(1);
        } finally {
            putenv("PATH={$originalPath}");
        }
    }

    public function test_it_fails_when_the_doxygen_run_itself_fails(): void
    {
        $doxyfile = base_path('Doxyfile');
        $backup = base_path('Doxyfile.bak-test');

        rename($doxyfile, $backup);

        try {
            $this->artisan('docs:api')->assertExitCode(1);
        } finally {
            rename($backup, $doxyfile);
        }
    }

    public function test_it_generates_the_doxygen_documentation_successfully(): void
    {
        $indexPath = public_path('docs/api/html/index.html');

        File::deleteDirectory(public_path('docs/api'));

        $this->artisan('docs:api')
            ->expectsOutputToContain('Quellcode-Dokumentation generiert')
            ->assertExitCode(0);

        $this->assertFileExists($indexPath);
    }
}
