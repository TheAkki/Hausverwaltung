<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_relevant_for_billing')->default(true);
            $table->timestamps();
            $table->boolean('is_tax_relevant')->default(true);

            // Analog zu BillingTemplate: null = eingebaut/systemweit (von CostCategorySeeder
            // angelegt, für jeden Account sichtbar, aber von niemandem bearbeitbar); gesetzt =
            // eine von diesem Account selbst angelegte, nur diesem Account sichtbare Kategorie.
            $table->foreignId('user_group_id')->nullable()->constrained('groups')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_categories');
    }
};
