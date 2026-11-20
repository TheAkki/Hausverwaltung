<?php

/*
 * Screenshots der Bedienungsanleitung (php artisan docs:screenshots).
 *
 * Der Befehl rendert die Seiten mit frei erfundenen Beispieldaten
 * (DocumentationDemoSeeder) auf einer eigenen SQLite-Datenbank im Speicher und
 * fotografiert sie mit Chromium im Headless-Modus ab. Ein Chromium aus Snap kann nur
 * Dateien unterhalb des Home-Verzeichnisses lesen – daher liegt das Arbeitsverzeichnis
 * im Projekt (storage/framework, nicht versioniert).
 */
return [
    'chromium' => env('DOCS_CHROMIUM', 'chromium'),

    // Stichtag der Beispieldaten – gleiche Bilder bei jedem Lauf.
    'demo_date' => env('DOCS_DEMO_DATE', '2026-03-15'),

    'window_width' => 1280,

    'window_height' => 900,

    'timeout_seconds' => 120,

    'work_directory' => storage_path('framework/docs-screenshots'),

    'screenshot_directory' => resource_path('docs/screenshots'),
];
