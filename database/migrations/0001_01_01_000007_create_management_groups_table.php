<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('management_groups', function (Blueprint $table) {
            $table->id();
            $table->text('description')->nullable();
            $table->date('billing_start')->nullable();
            $table->foreignId('user_group_id')->constrained('groups')->cascadeOnDelete();
            $table->timestamps();
            $table->foreignId('default_billing_template_id')->nullable()->constrained('billing_templates')->nullOnDelete();
            $table->boolean('kontosystem_enabled')->default(false);
            $table->string('variable_cost_forecast_mode')->default('last_booking');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('management_groups');
    }
};
