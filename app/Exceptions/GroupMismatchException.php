<?php

namespace App\Exceptions;

/**
 * Stammdaten (Mieter, Rechnungssteller, eigene Kategorien) und die Verwaltungsgruppe,
 * in der sie verwendet werden, liegen in verschiedenen Benutzergruppen.
 *
 * Eine RuntimeException, damit der Seeder mit dieser Meldung abbricht; in Web und API
 * wird sie in bootstrap/app.php zu einem Validierungsfehler am Feld $field.
 */
class GroupMismatchException extends \RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
