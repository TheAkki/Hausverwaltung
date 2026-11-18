<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_issuer_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->decimal('effective_amount', 10, 2)->nullable();
            $table->string('invoice_number')->nullable();
            $table->date('invoice_date');
            $table->date('booking_date')->nullable();
            $table->text('description')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_original_name')->nullable();
            $table->string('periodicity')->default('once');
            $table->date('recurrence_start')->nullable();
            $table->date('recurrence_end')->nullable();
            $table->timestamps();
            $table->foreignId('invoice_issuer_account_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('costable');
            $table->boolean('disable_amount_cap')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
