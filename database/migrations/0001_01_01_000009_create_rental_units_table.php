<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('management_unit_id')->constrained()->cascadeOnDelete();
            $table->decimal('size', 8, 2)->nullable();
            $table->string('type');
            $table->boolean('is_relevant_for_billing')->default(true);
            $table->timestamps();
            $table->string('description')->nullable();
            $table->string('floor')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_units');
    }
};
