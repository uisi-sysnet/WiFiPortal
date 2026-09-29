<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_devices', function (Blueprint $table) {
            $table->string('model', 64)->nullable()->after('name');
            $table->string('mac_address', 17)->nullable()->unique()->after('snmp_port'); // AA:BB:CC:DD:EE:FF
            $table->string('serial_number', 64)->nullable()->after('mac_address');
            $table->string('firmware_version', 64)->nullable()->after('serial_number');
        });
    }

    public function down(): void
    {
        Schema::table('network_devices', function (Blueprint $table) {
            $table->dropUnique(['mac_address']);
            $table->dropColumn(['model', 'mac_address', 'serial_number', 'firmware_version']);
        });
    }
};