<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mikrotik_routers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('location')->nullable();

            // API access
            $table->string('host');
            $table->unsignedInteger('api_port')->default(8728);
            $table->boolean('use_ssl')->default(false);
            $table->string('username', 64);
            $table->text('password'); // encrypted by the model cast

            // Interfaces
            $table->string('wan_interface', 64);
            $table->string('hotspot_interface', 64);

            // Address plan (allocated automatically)
            $table->unsignedInteger('block_index')->unique();
            $table->string('subnet', 18)->unique();
            $table->string('gateway', 15);
            $table->string('pool_start', 15);
            $table->string('pool_end', 15);

            // Facts read from the router
            $table->string('identity')->nullable();
            $table->string('board_name')->nullable();
            $table->string('ros_version')->nullable();

            // Provisioning state
            $table->string('status', 20)->default('pending')->index();
            $table->text('last_error')->nullable();
            $table->json('provision_log')->nullable();
            $table->timestamp('provisioned_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mikrotik_routers');
    }
};
