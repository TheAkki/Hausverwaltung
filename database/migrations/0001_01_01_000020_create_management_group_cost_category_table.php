<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fester, kurzer Name: der automatisch erzeugte (74 Zeichen) überschreitet die
     * Grenze von MySQL/MariaDB (64) und PostgreSQL (63).
     */
    private const UNIQUE_INDEX = 'mg_cost_category_unique';

    public function up(): void
    {
        Schema::create('management_group_cost_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('management_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cost_category_id')->constrained()->cascadeOnDelete();
            $table->string('allocation_key')->default('area');
            $table->timestamps();
            $table->unique(['management_group_id', 'cost_category_id'], self::UNIQUE_INDEX);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('management_group_cost_category');
    }
};
