<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aufschlüsselung, welcher Mietvertrag wie viel zu einer gebuchten Mietzahlung
 * beigetragen hat (Konzept.md "### Mietvertrag": ein gekoppelter Mietvertrag wird nie
 * selbst gebucht, seine Miete fließt stattdessen in die Zahlung des referenzierten
 * Mietvertrags ein – siehe RentPaymentGenerator). Enthält je Mietzahlung immer
 * mindestens die Zeile des Mietvertrags selbst, plus eine weitere Zeile pro in diesem
 * Monat aktiv gekoppeltem Mietvertrag. Rein abgeleitet/automatisch erzeugt, keine
 * eigene Nutzereingabe nötig.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_rent_payment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rent_payment_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_rent_payment');
    }
};
