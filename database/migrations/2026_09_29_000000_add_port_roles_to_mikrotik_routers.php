<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mikrotik_routers', function (Blueprint $table) {
            $table->string('model', 64)->nullable();

            // {"ether1":"wan","ether2":"lan","ether3":"hotspot","ether4":"none",...}
            $table->json('port_roles')->nullable();

            // keep | dhcp | static
            $table->string('wan_mode', 10)->default('keep');
            $table->string('wan_address', 18)->nullable();
            $table->string('wan_gateway', 15)->nullable();

            $table->string('lan_subnet', 18)->nullable()->unique();
            $table->string('lan_gateway', 15)->nullable();
            $table->string('lan_pool_start', 15)->nullable();
            $table->string('lan_pool_end', 15)->nullable();

            $table->dropColumn('hotspot_interface');
        });
    }

    public function down(): void
    {
        Schema::table('mikrotik_routers', function (Blueprint $table) {
            $table->string('hotspot_interface', 64)->nullable();
            $table->dropUnique(['lan_subnet']);
            $table->dropColumn([
                'model', 'port_roles', 'wan_mode', 'wan_address', 'wan_gateway',
                'lan_subnet', 'lan_gateway', 'lan_pool_start', 'lan_pool_end',
            ]);
        });
    }
};
