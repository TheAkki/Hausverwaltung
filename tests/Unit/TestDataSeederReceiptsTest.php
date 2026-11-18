<?php

// KI-generiert

namespace Tests\Unit;

use App\Models\Invoice;
use App\Services\InvoiceReceipt;
use Database\Seeders\CostCategorySeeder;
use Database\Seeders\TestDataSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * "Rechnung" einer Rechnung in den Seed-Daten: relativer Pfad zu einer Datei, die
 * hochgeladen und der Rechnung als Beleg zugeordnet wird (Konzept.md "## Testdaten").
 */
class TestDataSeederReceiptsTest extends TestDataSeederTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(InvoiceReceipt::DISK);
        $this->seed(CostCategorySeeder::class);
    }

    private function seedWithReceipt(string $reference): void
    {
        file_put_contents($this->path, <<<YAML
        lang: "de"

        Account-1:
            Name: "Beleg User"
            Passwort: "geheim123"

            Verwaltungsgruppe-1:
                Beschreibung: "Beleg Gruppe"

            Rechnungssteller-1:
                Firma: "Versicherung AG"
                Ort: "Stadt"

            Rechnungen:
                Verwaltungsgruppe-1:
                    Rechnung-1:
                        Rechnungssteller: Rechnungssteller-1
                        Rechnung: "{$reference}"
                        Rechnungsdatum: 1.1.2026
                        Betrag: 100
                        Beschreibung: "Gebäudeversicherung"
                        Wiederholung: "einmalig"
                        Zuordnung:
                            Kategorie: "Sach- und Haftpflichtversicherung"
        YAML);

        $this->seed(TestDataSeeder::class);
    }

    private function receipt(string $name, string $contents = '%PDF-1.4'): void
    {
        if (! is_dir(dirname($this->fixturePath($name)))) {
            mkdir(dirname($this->fixturePath($name)), recursive: true);
        }

        file_put_contents($this->fixturePath($name), $contents);
    }

    // KI-generiert
    public function test_the_file_is_uploaded_and_attached_relative_to_the_seed_file(): void
    {
        $this->receipt('2026-01-02__Rechnung.pdf');

        $this->seedWithReceipt('2026-01-02__Rechnung.pdf');

        $invoice = Invoice::sole();
        $this->assertSame('2026-01-02__Rechnung.pdf', $invoice->file_original_name);
        Storage::disk(InvoiceReceipt::DISK)->assertExists($invoice->file_path);
        $this->assertSame('%PDF-1.4', Storage::disk(InvoiceReceipt::DISK)->get($invoice->file_path));
    }

    // KI-generiert
    public function test_seeding_again_keeps_the_same_receipt_and_a_new_file_replaces_it(): void
    {
        $this->receipt('alt.pdf');
        $this->seedWithReceipt('alt.pdf');
        $first = Invoice::sole()->file_path;

        $this->seed(TestDataSeeder::class);
        $this->assertSame($first, Invoice::sole()->file_path);
        $this->assertCount(1, Storage::disk(InvoiceReceipt::DISK)->allFiles());

        $this->receipt('neu.pdf', '%PDF-neu');
        $this->seedWithReceipt('neu.pdf');

        $this->assertSame('neu.pdf', Invoice::sole()->file_original_name);
        Storage::disk(InvoiceReceipt::DISK)->assertMissing($first);
        $this->assertCount(1, Storage::disk(InvoiceReceipt::DISK)->allFiles());
    }

    // KI-generiert
    public function test_a_missing_file_aborts_with_the_invoice_in_the_message(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/"Gebäudeversicherung".*Referenzierte Datei nicht gefunden: "fehlt\.pdf"/');

        $this->seedWithReceipt('fehlt.pdf');
    }

    // KI-generiert
    public function test_a_file_type_that_cannot_be_uploaded_is_rejected(): void
    {
        $this->receipt('notizen.txt', 'Text');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Der Beleg "notizen.txt" hat keinen erlaubten Dateityp');

        $this->seedWithReceipt('notizen.txt');
    }

    // KI-generiert
    public function test_a_file_above_the_upload_limit_is_rejected(): void
    {
        $this->receipt('gross.pdf');
        $handle = fopen($this->fixturePath('gross.pdf'), 'r+');
        ftruncate($handle, InvoiceReceipt::MAX_KILOBYTES * 1024 + 1);
        fclose($handle);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ist größer als 10 MB');

        $this->seedWithReceipt('gross.pdf');
    }
}
