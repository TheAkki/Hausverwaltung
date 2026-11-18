<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_claims', function (Blueprint $table) {
            $table->id();
            // Entweder lease_id (Nachzahlung/Rückzahlung eines Mietvertrags) oder
            // rental_unit_id (Leerstand, Konzept.md "#### Berechnung": "Zu diesem
            // Leerstand müssen auch Forderungen, die z. B. von einer
            // Abrechnungsgesellschaft kommen, verbucht werden.") – nie beide, nie
            // keines; erzwungen in SettlementClaimController, nicht per DB-Constraint.
            $table->foreignId('lease_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('rental_unit_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('billing_interval_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->date('date');
            $table->date('booking_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_claims');
    }
};
