<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_devices', function (Blueprint $table) {
            // Reserved like `clients`: filled once per-vendor SNMP collection is added.
            $table->unsignedTinyInteger('utilization')->nullable()->after('clients'); // percent, 0-100
        });
    }

    public function down(): void
    {
        Schema::table('network_devices', function (Blueprint $table) {
            $table->dropColumn('utilization');
        });
    }
};