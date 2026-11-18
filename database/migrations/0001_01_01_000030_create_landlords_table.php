<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A Vermieter (landlord) either belongs to a Benutzergruppe/Account (the account-
     * level Vermieter, user_group_id set) or to a single Verwaltungsgruppe (an override
     * for that group, management_group_id set). Exactly one of the two is set.
     */
    public function up(): void
    {
        Schema::create('landlords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_group_id')->nullable()->constrained('groups')->cascadeOnDelete();
            $table->foreignId('management_group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('salutation')->nullable();
            $table->string('first_name')->nullable();
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('street')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile_phone')->nullable();
            $table->string('iban')->nullable();
            $table->string('bic')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landlords');
    }
};
