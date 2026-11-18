<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_unit_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('rent_adjustment_type')->default('fixed');
            $table->decimal('cold_rent', 10, 2);
            $table->timestamps();
            $table->decimal('deposit_amount', 10, 2)->nullable();
            $table->unsignedInteger('min_term_months')->default(0);
            $table->string('notice_period')->nullable();
            $table->text('notes')->nullable();

            // "Vorvertrag": a pre-contract that is not billed on its own – its period and
            // Betriebskostenvorauszahlung roll into the following lease of the same
            // Mieteinheit (Konzept.md "### Mietvertrag"/"### Mieteinheit").
            $table->boolean('is_pre_contract')->default(false);

            // "Mietvertrag: <ref>"-Kopplung (Konzept.md "### Mietvertrag"): statt einer
            // eigenen MieterId kann ein Mietvertrag auf den Mietvertrag einer anderen
            // Mieteinheit derselben Verwaltungseinheit verweisen (z. B. ein Stellplatz,
            // der an die Wohnung desselben Mieters gekoppelt ist). Diese Spalte hält den
            // Verweis dauerhaft fest, damit die Steuerübersicht den gekoppelten
            // Mietvertrag in den referenzierten einrechnen kann, statt ihn als eigene
            // Zeile zu listen (siehe FinancialOverviewService/RentPaymentGenerator).
            $table->foreignId('coupled_to_lease_id')->nullable()->constrained('leases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leases');
    }
};
