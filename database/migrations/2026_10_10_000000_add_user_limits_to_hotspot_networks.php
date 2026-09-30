<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hotspot networks get any size from /24 to /16 and an address the admin can
 * change, so they are no longer numbered blocks of one fixed size. The subnet
 * itself is what gets checked for overlaps. max_users caps the DHCP range
 * (null = no limit: the whole subnet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotspot_networks', function (Blueprint $table) {
            $table->unsignedInteger('max_users')->nullable()->after('pool_end');
        });

        // Unique index first: SQLite can't drop an indexed column.
        Schema::table('hotspot_networks', function (Blueprint $table) {
            $table->dropUnique(['block_index']);
        });
        Schema::table('hotspot_networks', function (Blueprint $table) {
            $table->dropColumn('block_index');
        });
    }

    public function down(): void
    {
        Schema::table('hotspot_networks', function (Blueprint $table) {
            $table->dropColumn('max_users');
            $table->unsignedInteger('block_index')->nullable();
        });
    }
};
