<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_issuers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('street');
            $table->string('postal_code');
            $table->string('city');
            $table->string('email')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->string('salutation')->nullable();
            $table->string('company_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile_phone')->nullable();
            $table->string('iban')->nullable();
            $table->string('bic')->nullable();
            $table->foreignId('user_group_id')->nullable()->constrained('groups')->cascadeOnDelete();
            $table->string('first_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_issuers');
    }
};
