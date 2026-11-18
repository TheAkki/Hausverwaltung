<?php

namespace Tests\Unit;

use App\Models\BillingTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BillingTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_header_image_data_uri_is_null_without_an_image(): void
    {
        $template = BillingTemplate::create(['name' => 'Ohne Bild', 'content' => 'Inhalt']);

        $this->assertNull($template->headerImageDataUri());
    }

    public function test_header_image_data_uri_embeds_the_images_bytes_and_mime_type(): void
    {
        Storage::fake('public');

        // Echte PNG-Bytes schreiben (nicht UploadedFile::fake()->create(), dessen
        // Größenangabe unter Storage::fake() keine tatsächlich lesbaren Bytes erzeugt):
        // ein minimales 1x1-Pixel-PNG.
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $path = 'billing-template-images/logo.png';
        Storage::disk('public')->put($path, $pngBytes);
        $template = BillingTemplate::create(['name' => 'Mit Bild', 'content' => 'Inhalt', 'header_image_path' => $path]);

        $dataUri = $template->headerImageDataUri();

        // Kein Speicher-Link (DomPDF lädt "entfernte" URLs standardmäßig nicht, siehe
        // BillingStatementController) – die Bilddaten müssen direkt eingebettet sein.
        $this->assertNotNull($dataUri);
        $this->assertSame('data:image/png;base64,'.base64_encode($pngBytes), $dataUri);
    }

    public function test_header_image_data_uri_is_null_when_the_file_is_missing_from_disk(): void
    {
        Storage::fake('public');

        $template = BillingTemplate::create([
            'name' => 'Verwaistes Bild', 'content' => 'Inhalt',
            'header_image_path' => 'billing-template-images/does-not-exist.png',
        ]);

        $this->assertNull($template->headerImageDataUri());
    }
}
