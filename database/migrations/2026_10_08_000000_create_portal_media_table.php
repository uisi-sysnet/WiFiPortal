<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_media', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10);                         // image | video
            $table->string('original_name');
            $table->string('alt', 160)->nullable();             // description for screen readers
            $table->string('path')->nullable();                 // optimized file, on the public disk
            $table->string('poster_path')->nullable();          // video still shown before Play
            $table->string('source_path')->nullable();          // upload waiting for ffmpeg
            $table->unsignedInteger('bytes')->nullable();
            $table->unsignedInteger('poster_bytes')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->decimal('duration', 6, 2)->nullable();      // seconds
            $table->string('status', 12)->default('ready');     // processing | ready | failed
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_media');
    }
};
