<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rent_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_interval_id')->constrained()->cascadeOnDelete();
            $table->date('period_date');
            $table->decimal('amount', 10, 2);
            $table->date('booking_date')->nullable();
            $table->timestamps();
            $table->decimal('rent_amount', 10, 2)->nullable();
            $table->decimal('utility_amount', 10, 2)->nullable();

            $table->unique(['lease_interval_id', 'period_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rent_payments');
    }
};
