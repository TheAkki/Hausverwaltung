<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Seed-Daten
    |--------------------------------------------------------------------------
    |
    | YAML-Datei, aus der der TestDataSeeder Testdaten liest (Konzept.md
    | "## Testdaten"). "From-File"-Verweise darin werden relativ zum Projekt oder
    | zum Ordner dieser Datei aufgelöst.
    |
    */

    'data_file' => env('SEED_DATA_FILE', base_path('private-assets/seeddata.yaml')),

];
