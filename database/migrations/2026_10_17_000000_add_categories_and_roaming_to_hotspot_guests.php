<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registrations get a category (resident, visitor, student), each with its own
 * validity set in Settings, and can roam: while a registration is valid, the
 * same login is used on any router and network without the captive portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotspot_guests', function (Blueprint $table) {
            $table->string('category', 10)->default('visitor')->index(); // resident | visitor | student
            $table->string('school', 120)->nullable();
            $table->string('student_number', 40)->nullable();
            $table->text('password')->nullable();                          // encrypted; reused when roaming
            $table->json('local_router_ids')->nullable();                  // routers holding a local hotspot user (no RADIUS)
            $table->timestamp('last_connected_at')->nullable();
            $table->unsignedInteger('roams')->default(0);                  // logins on other routers/networks without the portal
            $table->index(['mac', 'expires_at']);
        });

        DB::table('hotspot_guests')->where('resident', true)->update(['category' => 'resident']);
    }

    public function down(): void
    {
        Schema::table('hotspot_guests', function (Blueprint $table) {
            $table->dropIndex(['mac', 'expires_at']);
            $table->dropIndex(['category']);
        });
        Schema::table('hotspot_guests', function (Blueprint $table) {
            $table->dropColumn(['category', 'school', 'student_number', 'password', 'local_router_ids', 'last_connected_at', 'roams']);
        });
    }
};
