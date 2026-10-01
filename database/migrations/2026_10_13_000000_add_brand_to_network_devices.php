<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Manufacturer of an access point or switch (Ubiquiti, MikroTik, TP-Link, ...), typed in by the admin. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_devices', function (Blueprint $table) {
            $table->string('brand', 64)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('network_devices', function (Blueprint $table) {
            $table->dropColumn('brand');
        });
    }
};
