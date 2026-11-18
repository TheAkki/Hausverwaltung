<?php

namespace App\Support;

/**
 * Formats an IBAN into 4-character blocks for display, per Konzept.md
 * ("IBANs sollen der Lesbarkeit in 4er Blöcken angezeigt werden").
 */
class IbanFormatter
{
    public static function format(?string $iban): ?string
    {
        if ($iban === null || trim($iban) === '') {
            return $iban;
        }

        $normalized = strtoupper(str_replace(' ', '', $iban));

        return trim(chunk_split($normalized, 4, ' '));
    }
}
