<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->longText('content');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->longText('header')->nullable();
            $table->longText('footer')->nullable();
            // Konzept.md "### Template": "Der Content soll von der Formatierung getrennt
            // sein. Damit lassen sich Templates besser kopieren, wenn Anpassungen gemacht
            // werden müssen." – optionales CSS, getrennt vom Content-Markup; beim Rendern
            // (BillingTemplateRenderer) zusammengeführt.
            $table->longText('css_content')->nullable();
            $table->string('header_image_path')->nullable();
            $table->foreignId('user_group_id')->nullable()->constrained('groups')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_templates');
    }
};
