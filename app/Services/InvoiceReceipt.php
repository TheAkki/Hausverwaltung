<?php

namespace App\Services;

use App\Models\Invoice;
use App\Support\FileStorage;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;

/**
 * Beleg (Datei) einer Rechnung speichern, ersetzen und entfernen – dieselbe Logik für
 * Web, REST-API und Seeder (Feld "Rechnung" der Seed-Daten). Erlaubt sind die
 * Dateitypen aus ALLOWED_EXTENSIONS bis MAX_KILOBYTES.
 */
final class InvoiceReceipt
{
    public const ALLOWED_EXTENSIONS = FileStorage::ALLOWED_EXTENSIONS;

    public const MAX_KILOBYTES = FileStorage::MAX_KILOBYTES;

    private const BYTES_PER_KILOBYTE = 1024;

    /**
     * Validierungsregeln für ein hochgeladenes Belegfeld.
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        return FileStorage::uploadRules();
    }

    /**
     * Speichert die Datei als Beleg der Rechnung; ein vorhandener Beleg wird ersetzt.
     */
    public function attach(Invoice $invoice, UploadedFile|File $file, string $originalName): void
    {
        $this->remove($invoice);

        $invoice->update([
            'file_path' => FileStorage::store($file, FileStorage::INVOICES),
            'file_original_name' => $originalName,
        ]);
    }

    /**
     * Hängt eine Datei aus dem Dateisystem an (Seeder). Hat die Rechnung bereits einen
     * Beleg mit demselben Dateinamen, bleibt er unverändert – ein erneuter Lauf legt
     * keine Kopie an.
     *
     * @throws \InvalidArgumentException bei unzulässigem Dateityp oder zu großer Datei
     */
    public function attachPath(Invoice $invoice, string $path): void
    {
        $name = basename($path);

        if ($invoice->file_path !== null && $invoice->file_original_name === $name) {
            return;
        }

        $this->assertAcceptable($path);
        $this->attach($invoice, new File($path), $name);
    }

    /**
     * Entfernt die gespeicherte Datei (die Angaben an der Rechnung setzt der Aufrufer).
     */
    public function remove(Invoice $invoice): void
    {
        FileStorage::delete($invoice->file_path);
    }

    private function assertAcceptable(string $path): void
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \InvalidArgumentException(
                'Der Beleg "'.basename($path).'" hat keinen erlaubten Dateityp ('.implode(', ', self::ALLOWED_EXTENSIONS).').'
            );
        }

        if (filesize($path) > self::MAX_KILOBYTES * self::BYTES_PER_KILOBYTE) {
            throw new \InvalidArgumentException(
                'Der Beleg "'.basename($path).'" ist größer als '.(self::MAX_KILOBYTES / self::BYTES_PER_KILOBYTE).' MB.'
            );
        }
    }
}
