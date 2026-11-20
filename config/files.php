<?php

/*
 * Hochgeladene Dateien (Belege, Mietvertragsdokumente, Kopfbilder der Vorlagen).
 *
 * Sie liegen auf einer nicht öffentlichen Disk und werden nur über Routen mit
 * Berechtigungsprüfung ausgeliefert (FileDownloadController). Die öffentliche Disk
 * ("public", per /storage ohne Anmeldung erreichbar) darf hier nicht eingetragen werden.
 */
return [
    'disk' => env('HV_FILES_DISK', 'local'),

    // Disk, auf der hochgeladene Dateien früher lagen; nur für den Umzug (Migration).
    'legacy_disk' => 'public',

    'directories' => [
        'invoices' => 'invoices',
        'lease_documents' => 'lease-documents',
        'billing_template_images' => 'billing-template-images',
        'settlement_claims' => 'settlement-claims',
        'account_transfers' => 'account-transfers',
    ],
];
