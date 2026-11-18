<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->morphs('costable');
            $table->foreignId('billing_interval_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cost_category_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->timestamps();
            $table->date('period_date')->nullable();
            $table->date('booking_date')->nullable();
            // Von einem Rechnung-Intervall (Konzept.md "### Rechnungen", z. B. eine
            // Lohnerhöhung innerhalb eines Arbeitsvertrags) übernommene "Notiz" – nur bei
            // aus "Intervall-*" generierten Kostenzeilen gesetzt, sonst null.
            $table->text('note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('costs');
    }
};
