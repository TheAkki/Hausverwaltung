<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_issuer_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_issuer_id')->constrained()->cascadeOnDelete();
            $table->string('customer_number');
            $table->string('description')->nullable();
            $table->boolean('direct_debit')->default(false);
            $table->timestamps();
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_issuer_accounts');
    }
};
