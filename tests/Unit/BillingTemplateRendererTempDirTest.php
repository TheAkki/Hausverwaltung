<?php

// KI-generiert

namespace Tests\Unit;

use App\Services\BillingTemplateRenderer;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * mPDF schreibt seine Zwischendateien in das konfigurierte Verzeichnis
 * (config/pdf.php) statt nach vendor/, das z. B. im Docker-Image nicht
 * beschreibbar ist.
 */
class BillingTemplateRendererTempDirTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/hv-mpdf-test-'.uniqid();
        config(['pdf.temp_dir' => $this->directory]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    // KI-generiert
    public function test_the_configured_temp_dir_is_created_and_used_for_rendering(): void
    {
        $pdf = (new BillingTemplateRenderer)->renderPdf('<p>Inhalt</p>', '<p>Kopf</p>', '<p>Fuß</p>', []);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertDirectoryExists($this->directory);
    }

    // KI-generiert
    public function test_an_existing_temp_dir_is_reused(): void
    {
        File::ensureDirectoryExists($this->directory);
        File::put($this->directory.'/vorhanden.txt', 'bleibt');

        $pdf = (new BillingTemplateRenderer)->renderPdf('<p>Inhalt</p>', null, null, []);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertFileExists($this->directory.'/vorhanden.txt');
    }

    // KI-generiert
    public function test_the_default_temp_dir_lies_in_storage_not_in_vendor(): void
    {
        $default = require config_path('pdf.php');

        $this->assertStringStartsWith(storage_path(), $default['temp_dir']);
    }
}
