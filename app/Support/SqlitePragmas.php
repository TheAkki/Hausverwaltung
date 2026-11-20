<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Prüft die SQLite-Einstellungen aus der Umgebung (DB_JOURNAL_MODE, DB_SYNCHRONOUS,
 * DB_BUSY_TIMEOUT), bevor Laravel sie als PRAGMA an die Datenbank gibt – ein Tippfehler
 * soll beim Start auffallen statt als SQL-Fehler oder still ignoriert.
 *
 * Standard ist WAL mit synchronous=NORMAL: Schreiben ohne fsync je Anweisung, nur bei
 * Checkpoints. Bei Stromausfall können die letzten Änderungen fehlen, die Datei bleibt
 * aber heil. Für Datenbanken auf Netzlaufwerken DB_JOURNAL_MODE=delete setzen.
 */
final class SqlitePragmas
{
    public const DEFAULT_JOURNAL_MODE = 'wal';

    public const DEFAULT_SYNCHRONOUS = 'normal';

    /** Wartezeit in Millisekunden, wenn gerade ein anderer Prozess schreibt. */
    public const DEFAULT_BUSY_TIMEOUT = 5000;

    public const JOURNAL_MODES = ['delete', 'truncate', 'persist', 'memory', 'wal', 'off'];

    public const SYNCHRONOUS_MODES = ['off', 'normal', 'full', 'extra'];

    public static function journalMode(?string $value): string
    {
        return self::oneOf('DB_JOURNAL_MODE', $value, self::JOURNAL_MODES, self::DEFAULT_JOURNAL_MODE);
    }

    public static function synchronous(?string $value): string
    {
        return self::oneOf('DB_SYNCHRONOUS', $value, self::SYNCHRONOUS_MODES, self::DEFAULT_SYNCHRONOUS);
    }

    public static function busyTimeout(int|string|null $value): int
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_BUSY_TIMEOUT;
        }

        if (! is_int($value) && ! ctype_digit($value)) {
            throw new InvalidArgumentException("DB_BUSY_TIMEOUT muss eine nicht negative ganze Zahl (Millisekunden) sein, nicht: {$value}");
        }

        return (int) $value;
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function oneOf(string $name, ?string $value, array $allowed, string $default): string
    {
        if ($value === null || trim($value) === '') {
            return $default;
        }

        $normalized = strtolower(trim($value));
        if (! in_array($normalized, $allowed, true)) {
            throw new InvalidArgumentException("{$name} muss einer dieser Werte sein: ".implode(', ', $allowed).", nicht: {$value}");
        }

        return $normalized;
    }
}
