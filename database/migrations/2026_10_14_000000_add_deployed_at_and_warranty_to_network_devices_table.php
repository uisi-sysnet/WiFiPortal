<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_devices', function (Blueprint $table) {
            // When the device was physically installed / activated.
            $table->date('deployed_at')->nullable()->after('location');

            // Free-text warranty reference (e.g. "3 years, ends 2028-05-14",
            // or the vendor ticket/case number). Kept flexible on purpose.
            $table->string('warranty', 255)->nullable()->after('deployed_at');
        });
    }

    public function down(): void
    {
        Schema::table('network_devices', function (Blueprint $table) {
            $table->dropColumn(['deployed_at', 'warranty']);
        });
    }
};