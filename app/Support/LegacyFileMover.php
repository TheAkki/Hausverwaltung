<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Zieht hochgeladene Dateien von der früher verwendeten öffentlichen Disk
 * (config files.legacy_disk) auf die nicht öffentliche Disk (FileStorage) um. Die
 * gespeicherten Pfade bleiben gleich, die Datensätze müssen also nicht angepasst werden.
 * Eine Datei, die es am Ziel schon gibt, bleibt dort unverändert; die alte wird dann
 * nur entfernt.
 */
final class LegacyFileMover
{
    /**
     * @return int Anzahl verschobener Dateien
     */
    public function toPrivate(): int
    {
        return $this->move(Storage::disk((string) config('files.legacy_disk')), FileStorage::disk());
    }

    /**
     * Umkehrung von toPrivate() (Rücknahme der Migration).
     *
     * @return int Anzahl verschobener Dateien
     */
    public function toLegacy(): int
    {
        return $this->move(FileStorage::disk(), Storage::disk((string) config('files.legacy_disk')));
    }

    private function move(Filesystem $from, Filesystem $to): int
    {
        if (config('files.legacy_disk') === FileStorage::diskName()) {
            return 0;
        }

        $moved = 0;

        foreach ((array) config('files.directories') as $directory) {
            foreach ($from->allFiles((string) $directory) as $path) {
                if (! $to->exists($path)) {
                    $to->writeStream($path, $from->readStream($path));
                    $moved++;
                }

                $from->delete($path);
            }
        }

        return $moved;
    }
}
