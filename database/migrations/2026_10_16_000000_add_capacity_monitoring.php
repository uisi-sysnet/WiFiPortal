<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capacity monitoring. Each router has a rated number of hotspot users (from
 * its model, or set by the admin) and reports CPU, memory and DHCP leases on
 * every `routers:poll`. When a router or a hotspot network's address pool
 * gets busy or full, an alert is opened (and closed again when it recovers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mikrotik_routers', function (Blueprint $table) {
            $table->unsignedInteger('rated_users')->nullable();       // null = the model's default
            $table->unsignedTinyInteger('cpu_load')->nullable();      // % at the last poll
            $table->decimal('cpu_avg', 5, 1)->nullable();             // smoothed %, so one spike isn't an alert
            $table->unsignedBigInteger('free_memory')->nullable();    // bytes
            $table->unsignedBigInteger('total_memory')->nullable();
        });

        Schema::table('hotspot_networks', function (Blueprint $table) {
            $table->unsignedInteger('leases')->nullable();            // DHCP addresses handed out
        });

        Schema::create('capacity_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mikrotik_router_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hotspot_network_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 10);                 // users | cpu | pool
            $table->string('level', 10);                // busy | full
            $table->decimal('value', 5, 1);             // % of capacity
            $table->text('message');                    // what is full and what to do
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();
            $table->index(['mikrotik_router_id', 'kind', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capacity_alerts');
        Schema::table('hotspot_networks', function (Blueprint $table) {
            $table->dropColumn('leases');
        });
        Schema::table('mikrotik_routers', function (Blueprint $table) {
            $table->dropColumn(['rated_users', 'cpu_load', 'cpu_avg', 'free_memory', 'total_memory']);
        });
    }
};
