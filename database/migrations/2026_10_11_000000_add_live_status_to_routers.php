<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live state from `routers:poll` (every 30 seconds by default): whether the
 * router answers its API, and how many hotspot users are logged in.
 * Separate from `status`, which only says whether the configuration was applied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mikrotik_routers', function (Blueprint $table) {
            $table->string('link_status', 10)->default('unknown')->index(); // unknown | online | offline
            $table->unsignedInteger('active_users')->nullable();            // null = not known (offline / never polled)
            $table->unsignedSmallInteger('poll_failures')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->text('poll_error')->nullable();
        });

        Schema::table('hotspot_networks', function (Blueprint $table) {
            $table->unsignedInteger('active_users')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mikrotik_routers', function (Blueprint $table) {
            $table->dropIndex(['link_status']);
        });
        Schema::table('mikrotik_routers', function (Blueprint $table) {
            $table->dropColumn(['link_status', 'active_users', 'poll_failures', 'last_seen_at', 'last_polled_at', 'poll_error']);
        });
        Schema::table('hotspot_networks', function (Blueprint $table) {
            $table->dropColumn('active_users');
        });
    }
};
