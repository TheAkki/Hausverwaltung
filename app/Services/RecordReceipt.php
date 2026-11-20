<?php

namespace App\Services;

use App\Models\AccountTransfer;
use App\Models\SettlementClaim;
use App\Support\FileStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;

/**
 * Beleg (Datei) einer Forderung oder Umbuchung speichern, ersetzen und entfernen – eine
 * Logik für Web und API (Rechnungen: InvoiceReceipt). Der Datensatz hat die Spalten
 * file_path und file_original_name.
 */
final class RecordReceipt
{
    /** Formularfeld für die Datei. */
    public const FIELD_FILE = 'file';

    /** Formularfeld: vorhandenen Beleg entfernen. */
    public const FIELD_REMOVE = 'remove_file';

    /** Dateiart (FileStorage) je Modell. */
    private const KINDS = [
        SettlementClaim::class => FileStorage::SETTLEMENT_CLAIMS,
        AccountTransfer::class => FileStorage::ACCOUNT_TRANSFERS,
    ];

    /**
     * Validierungsregeln für ein optionales Belegfeld und das Entfernen.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            self::FIELD_FILE => ['nullable', ...FileStorage::uploadRules()],
            self::FIELD_REMOVE => ['boolean'],
        ];
    }

    /**
     * Speichert die Datei als Beleg; ein vorhandener wird ersetzt.
     */
    public function attach(SettlementClaim|AccountTransfer $record, UploadedFile|File $file, string $originalName): void
    {
        $this->remove($record);

        $record->update([
            'file_path' => FileStorage::store($file, self::KINDS[$record::class]),
            'file_original_name' => $originalName,
        ]);
    }

    /**
     * Entfernt Datei und Angaben am Datensatz.
     */
    public function remove(SettlementClaim|AccountTransfer $record): void
    {
        if ($record->file_path === null) {
            return;
        }

        FileStorage::delete($record->file_path);
        $record->update(['file_path' => null, 'file_original_name' => null]);
    }

    /**
     * Übernimmt Hochladen bzw. Entfernen aus einer validierten Anfrage.
     */
    public function applyUpload(SettlementClaim|AccountTransfer $record, ?UploadedFile $file, bool $remove): void
    {
        if ($file !== null) {
            $this->attach($record, $file, $file->getClientOriginalName());
        } elseif ($remove) {
            $this->remove($record);
        }
    }

    /**
     * Löscht die Datei, bevor der Datensatz gelöscht wird.
     */
    public function forget(Model $record): void
    {
        FileStorage::delete($record->getAttribute('file_path'));
    }
}
