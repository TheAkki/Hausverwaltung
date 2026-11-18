<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('last_name');
            $table->string('first_name')->nullable();
            $table->string('street');
            $table->string('postal_code');
            $table->string('city');
            $table->timestamps();
            $table->string('iban')->nullable();
            $table->string('bic')->nullable();
            $table->string('company_name')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile_phone')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_group_id')->nullable()->constrained('groups')->cascadeOnDelete();
            $table->string('salutation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
