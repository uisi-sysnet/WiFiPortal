<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_devices', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10)->index();                 // ap | switch
            $table->string('name', 64);
            $table->foreignId('mikrotik_router_id')->nullable()->constrained()->nullOnDelete(); // site
            $table->string('location')->nullable();

            // SNMP
            $table->string('host');
            $table->unsignedInteger('snmp_port')->default(161);
            $table->string('snmp_version', 3);                   // 1 | 2c | 3
            $table->text('community')->nullable();               // v1/v2c, encrypted
            $table->string('v3_username', 64)->nullable();
            $table->string('v3_security_level', 12)->nullable(); // noAuthNoPriv | authNoPriv | authPriv
            $table->string('v3_auth_protocol', 8)->nullable();   // MD5 | SHA | SHA256 | SHA512
            $table->text('v3_auth_password')->nullable();        // encrypted
            $table->string('v3_priv_protocol', 8)->nullable();   // DES | AES
            $table->text('v3_priv_password')->nullable();        // encrypted

            // What the device reports
            $table->string('sys_name')->nullable();
            $table->text('sys_descr')->nullable();
            $table->unsignedBigInteger('uptime_seconds')->nullable();
            $table->unsignedInteger('clients')->nullable();      // reserved: connected clients per AP

            // Status
            $table->string('status', 10)->default('unknown')->index(); // unknown | online | offline
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();
            $table->unique(['host', 'snmp_port']);
            $table->unique(['type', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_devices');
    }
};
