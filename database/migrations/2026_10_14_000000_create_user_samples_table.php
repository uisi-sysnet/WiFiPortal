<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * History for the "Users online" chart: the total hotspot users online,
 * saved every 5 minutes by `users:snapshot` (from what `routers:poll` last
 * read). Kept for about 13 months so the Year view always has 12 full months.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_samples', function (Blueprint $table) {
            $table->id();
            $table->timestamp('recorded_at')->index();
            $table->unsignedInteger('users');
            $table->unsignedInteger('routers_online');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_samples');
    }
};
