<?php

namespace App\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Die eine Stelle, an der hochgeladene Dateien liegen (config/files.php). Die Disk ist
 * nicht öffentlich; ausgeliefert wird nur über FileDownloadController.
 */
final class FileStorage
{
    public const INVOICES = 'invoices';

    public const LEASE_DOCUMENTS = 'lease_documents';

    public const BILLING_TEMPLATE_IMAGES = 'billing_template_images';

    public const SETTLEMENT_CLAIMS = 'settlement_claims';

    public const ACCOUNT_TRANSFERS = 'account_transfers';

    /** Erlaubte Dateitypen für Belege und Dokumente. */
    public const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

    public const MAX_KILOBYTES = 10240;

    /**
     * Validierungsregeln für ein hochgeladenes Beleg- bzw. Dokumentfeld.
     *
     * @return list<string>
     */
    public static function uploadRules(): array
    {
        return ['file', 'max:'.self::MAX_KILOBYTES, 'mimes:'.implode(',', self::ALLOWED_EXTENSIONS)];
    }

    /**
     * Speichert eine Datei im Verzeichnis ihrer Art; liefert den gespeicherten Pfad.
     */
    public static function store(UploadedFile|File $file, string $kind): string
    {
        $path = self::disk()->putFile(self::directory($kind), $file);

        if ($path === false) {
            throw new \RuntimeException('Die Datei konnte nicht gespeichert werden.');
        }

        return $path;
    }

    public static function diskName(): string
    {
        return (string) config('files.disk');
    }

    public static function disk(): FilesystemAdapter
    {
        $disk = Storage::disk(self::diskName());
        assert($disk instanceof FilesystemAdapter);

        return $disk;
    }

    /**
     * Liefert eine gespeicherte Datei aus – zum Anzeigen im Browser ($inline) oder als
     * Download unter ihrem ursprünglichen Namen; 404, wenn es sie nicht gibt.
     */
    public static function response(?string $path, ?string $name, bool $inline = false): StreamedResponse
    {
        abort_if($path === null || ! self::disk()->exists($path), 404);

        return $inline ? self::disk()->response($path, $name) : self::disk()->download($path, $name);
    }

    /**
     * Verzeichnis einer Dateiart (eine der Konstanten oben).
     */
    public static function directory(string $kind): string
    {
        $directory = config("files.directories.{$kind}");

        if (! is_string($directory) || $directory === '') {
            throw new \InvalidArgumentException("Unbekannte Dateiart \"{$kind}\".");
        }

        return $directory;
    }

    /**
     * Kopiert eine gespeicherte Datei unter neuem Namen ins selbe Verzeichnis; null,
     * wenn es keine Datei gibt.
     */
    public static function duplicate(?string $path): ?string
    {
        if ($path === null || ! self::disk()->exists($path)) {
            return null;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $copy = dirname($path).'/'.Str::uuid().($extension !== '' ? ".{$extension}" : '');
        self::disk()->copy($path, $copy);

        return $copy;
    }

    public static function delete(?string $path): void
    {
        if ($path !== null) {
            self::disk()->delete($path);
        }
    }
}
