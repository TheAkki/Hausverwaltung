<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Belege auch für Forderungen (Nachzahlung/Rückzahlung) und Umbuchungen – Konzept.md:
 * "Zu allen Ein- und Ausgaben sollen Rechnungen hinterlegt werden können."
 */
return new class extends Migration
{
    private const TABLES = ['settlement_claims', 'account_transfers'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('file_path')->nullable();
                $table->string('file_original_name')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['file_path', 'file_original_name']);
            });
        }
    }
};
