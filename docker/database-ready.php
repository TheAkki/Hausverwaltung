<?php

use Illuminate\Contracts\Console\Kernel;

/**
 * Prüft beim Containerstart, ob die konfigurierte Datenbank erreichbar ist.
 * Exit-Code 0: Verbindung steht; 1: (noch) nicht erreichbar, Grund auf stderr.
 * Aufruf aus dem Anwendungsverzeichnis: php database-ready.php
 */

require getcwd().'/vendor/autoload.php';

$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $app->make('db')->connection()->getPdo();
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage().PHP_EOL);
    exit(1);
}

exit(0);
