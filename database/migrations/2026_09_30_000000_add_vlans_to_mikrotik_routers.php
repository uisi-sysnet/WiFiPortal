<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mikrotik_routers', function (Blueprint $table) {
            // {"hotspot":{"id":10,"name":"vlan10-hotspot"},"mgmt":{"id":99,"name":"vlan99-mgmt","native":false},"test":{...}}
            $table->json('vlans')->nullable();

            foreach (['mgmt', 'test'] as $net) {
                $table->string("{$net}_subnet", 18)->nullable()->unique();
                $table->string("{$net}_gateway", 15)->nullable();
                $table->string("{$net}_pool_start", 15)->nullable();
                $table->string("{$net}_pool_end", 15)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('mikrotik_routers', function (Blueprint $table) {
            $table->dropUnique(['mgmt_subnet']);
            $table->dropUnique(['test_subnet']);
            $table->dropColumn([
                'vlans',
                'mgmt_subnet', 'mgmt_gateway', 'mgmt_pool_start', 'mgmt_pool_end',
                'test_subnet', 'test_gateway', 'test_pool_start', 'test_pool_end',
            ]);
        });
    }
};
