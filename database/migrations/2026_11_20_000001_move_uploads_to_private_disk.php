<?php

use App\Support\LegacyFileMover;
use Illuminate\Database\Migrations\Migration;

/**
 * Belege, Mietvertragsdokumente und Kopfbilder lagen auf der öffentlichen Disk und
 * waren über /storage/… ohne Anmeldung abrufbar. Sie ziehen auf die nicht öffentliche
 * Disk um (config/files.php) und werden nur noch über FileDownloadController
 * ausgeliefert. In Tests bleibt das echte Dateisystem unberührt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! app()->runningUnitTests()) {
            app(LegacyFileMover::class)->toPrivate();
        }
    }

    public function down(): void
    {
        if (! app()->runningUnitTests()) {
            app(LegacyFileMover::class)->toLegacy();
        }
    }
};
