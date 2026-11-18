<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Temporäre Dateien der PDF-Erzeugung (mPDF)
    |--------------------------------------------------------------------------
    |
    | mPDF legt beim Rendern Zwischendateien ab. Ohne Angabe wäre das ein
    | Ordner in vendor/, der in einer Installation (z. B. im Docker-Image) für
    | den Webserver meist nicht beschreibbar ist. Das Verzeichnis wird bei
    | Bedarf angelegt.
    |
    */

    'temp_dir' => env('PDF_TEMP_DIR', storage_path('framework/mpdf')),

];
