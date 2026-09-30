<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A router can now run several hotspot networks (one VLAN, subnet and hotspot
 * server each). Each network picks which captive portal design supplies its
 * login page and which supplies its advertisement page.
 *
 * Existing routers keep their hotspot as network "hotspot", with the same
 * subnet and portal code, so routers already configured keep working.
 */
return new class extends Migration
{
    private const ROUTER_COLUMNS = ['subnet', 'gateway', 'pool_start', 'pool_end', 'login_mode', 'login_url', 'portal_code'];

    public function up(): void
    {
        // Several named designs instead of one splash page.
        Schema::table('splash_pages', function (Blueprint $table) {
            $table->string('name', 80)->nullable();
        });
        DB::table('splash_pages')->whereNull('name')->update(['name' => 'Main portal']);
        $defaultPage = DB::table('splash_pages')->orderBy('id')->value('id');

        Schema::create('hotspot_networks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mikrotik_router_id')->constrained()->cascadeOnDelete();
            $table->string('key', 24);                  // names RouterOS objects: "hotspot", "hotspot-11"
            $table->string('name', 60);                 // shown in the dashboard, e.g. "Public WiFi"
            $table->unsignedSmallInteger('vlan_id');
            $table->string('interface', 32);            // VLAN interface name on the router

            // Address plan (allocated automatically, one hotspot block per network)
            $table->unsignedInteger('block_index')->unique();
            $table->string('subnet', 18)->unique();
            $table->string('gateway', 15);
            $table->string('pool_start', 15);
            $table->string('pool_end', 15);

            // builtin | portal (this app) | custom (external URL)
            $table->string('login_mode', 10)->default('portal');
            $table->string('login_url')->nullable();
            $table->string('portal_code', 16)->unique(); // public id in splash page URLs

            // Which design supplies each page (null = the first design)
            $table->foreignId('login_page_id')->nullable()->constrained('splash_pages')->nullOnDelete();
            $table->foreignId('ad_page_id')->nullable()->constrained('splash_pages')->nullOnDelete();

            $table->timestamps();
            $table->unique(['mikrotik_router_id', 'vlan_id']);
            $table->unique(['mikrotik_router_id', 'key']);
        });

        foreach (DB::table('mikrotik_routers')->orderBy('id')->get() as $router) {
            $vlans = json_decode((string) $router->vlans, true) ?: [];
            $vlan = $vlans['hotspot'] ?? config('hotspot.vlans.hotspot');

            DB::table('hotspot_networks')->insert([
                'mikrotik_router_id' => $router->id,
                'key' => 'hotspot',
                'name' => 'Public WiFi',
                'vlan_id' => $vlan['id'],
                'interface' => $vlan['name'],
                'block_index' => $router->block_index,
                'subnet' => $router->subnet,
                'gateway' => $router->gateway,
                'pool_start' => $router->pool_start,
                'pool_end' => $router->pool_end,
                'login_mode' => $router->login_mode,
                'login_url' => $router->login_url,
                'portal_code' => $router->portal_code,
                'login_page_id' => $defaultPage,
                'ad_page_id' => $defaultPage,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            unset($vlans['hotspot']);
            DB::table('mikrotik_routers')->where('id', $router->id)->update(['vlans' => json_encode($vlans)]);
        }

        Schema::table('hotspot_guests', function (Blueprint $table) {
            $table->foreignId('hotspot_network_id')->nullable()->after('mikrotik_router_id')
                ->constrained()->nullOnDelete();
        });
        foreach (DB::table('hotspot_networks')->get(['id', 'mikrotik_router_id']) as $network) {
            DB::table('hotspot_guests')->where('mikrotik_router_id', $network->mikrotik_router_id)
                ->update(['hotspot_network_id' => $network->id]);
        }

        // Unique indexes first: SQLite can't drop an indexed column.
        Schema::table('mikrotik_routers', function (Blueprint $table) {
            $table->dropUnique(['subnet']);
            $table->dropUnique(['portal_code']);
        });
        Schema::table('mikrotik_routers', function (Blueprint $table) {
            $table->dropColumn(self::ROUTER_COLUMNS);
        });
    }

    public function down(): void
    {
        Schema::table('mikrotik_routers', function (Blueprint $table) {
            $table->string('subnet', 18)->nullable()->unique();
            $table->string('gateway', 15)->nullable();
            $table->string('pool_start', 15)->nullable();
            $table->string('pool_end', 15)->nullable();
            $table->string('login_mode', 10)->default('builtin');
            $table->string('login_url')->nullable();
            $table->string('portal_code', 16)->nullable()->unique();
        });

        // Only each router's first network survives the rollback.
        foreach (DB::table('hotspot_networks')->orderBy('id')->get()->unique('mikrotik_router_id') as $n) {
            $vlans = json_decode((string) DB::table('mikrotik_routers')->where('id', $n->mikrotik_router_id)->value('vlans'), true) ?: [];
            $vlans['hotspot'] = ['id' => $n->vlan_id, 'name' => $n->interface];

            DB::table('mikrotik_routers')->where('id', $n->mikrotik_router_id)->update([
                'subnet' => $n->subnet,
                'gateway' => $n->gateway,
                'pool_start' => $n->pool_start,
                'pool_end' => $n->pool_end,
                'login_mode' => $n->login_mode,
                'login_url' => $n->login_url,
                'portal_code' => $n->portal_code,
                'vlans' => json_encode($vlans),
            ]);
        }

        Schema::table('hotspot_guests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hotspot_network_id');
        });
        Schema::dropIfExists('hotspot_networks');
        Schema::table('splash_pages', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
