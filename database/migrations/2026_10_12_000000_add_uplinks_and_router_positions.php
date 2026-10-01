<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each device is plugged into, for the link lines on the dashboard map:
 *   access point -> a switch (uplink_device_id) or straight into a router (mikrotik_router_id)
 *   switch       -> another switch, cascaded (uplink_device_id) or a router (mikrotik_router_id)
 * mikrotik_router_id stays the site router; behind a switch it is inherited from that switch.
 *
 * Routers get an optional map position so switch-to-router lines can be drawn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_devices', function (Blueprint $table) {
            // nullOnDelete: removing a switch leaves its devices unconnected rather than deleting them
            $table->foreignId('uplink_device_id')->nullable()->after('mikrotik_router_id')
                ->constrained('network_devices')->nullOnDelete();
        });

        Schema::table('mikrotik_routers', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('network_devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('uplink_device_id');
        });
        Schema::table('mikrotik_routers', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
