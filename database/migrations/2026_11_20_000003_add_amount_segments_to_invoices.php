<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Betragsintervalle einer wiederkehrenden Rechnung (z. B. Lohnerhöhung) an der Rechnung
 * festhalten – damit "Nicht zugeordnete Buchungen" den Sollbetrag jeder Periode kennt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->json('amount_segments')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('amount_segments');
        });
    }
};
