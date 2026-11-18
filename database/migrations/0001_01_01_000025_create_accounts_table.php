<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('management_group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('initial_balance', 10, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->text('description')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
