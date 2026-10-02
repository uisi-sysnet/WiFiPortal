<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clients connected per access point: read over SNMP on every poll, kept as one
 * row per access point per hour (average and peak), for the heat map and reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_devices', function (Blueprint $table) {
            $table->string('clients_oid', 160)->nullable();     // admin's own OID; empty = detect by brand
            $table->string('clients_mode', 5)->default('sum');  // sum: add up the values; count: count the rows
            $table->string('clients_source', 20)->nullable();   // profile that answered, "custom", or "none"
            $table->timestamp('clients_at')->nullable();        // when clients was last read
        });

        Schema::create('ap_client_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_device_id')->constrained('network_devices')->cascadeOnDelete();
            $table->timestamp('hour');                          // start of the hour (UTC)
            $table->unsignedInteger('samples')->default(0);     // polls in that hour
            $table->unsignedInteger('total')->default(0);       // clients added over those polls (average = total / samples)
            $table->unsignedInteger('peak')->default(0);
            $table->unique(['network_device_id', 'hour']);
            $table->index('hour');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ap_client_stats');
        Schema::table('network_devices', function (Blueprint $table) {
            $table->dropColumn(['clients_oid', 'clients_mode', 'clients_source', 'clients_at']);
        });
    }
};
