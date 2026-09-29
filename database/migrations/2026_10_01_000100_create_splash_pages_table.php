<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('splash_pages', function (Blueprint $table) {
            $table->id();
            $table->string('site_name', 80);
            $table->longText('html');            // full page, must contain [[form]]
            $table->longText('terms');           // Markdown
            $table->string('citizen_label', 60);
            $table->string('citizen_hint', 160)->nullable();
            $table->string('citizen_pattern', 200);
            $table->text('blocked_words')->nullable(); // one per line
            $table->string('success_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('splash_pages');
    }
};
