<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Edited in Settings: add, rename, delete.
        Schema::create('barangays', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->timestamps();
        });

        Schema::table('network_devices', function (Blueprint $table) {
            // restrictOnDelete: a barangay that still has devices can't be deleted
            $table->foreignId('barangay_id')->nullable()->after('mikrotik_router_id')->constrained()->restrictOnDelete();
            $table->decimal('latitude', 10, 7)->nullable()->after('location');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->index(['type', 'barangay_id']);
        });
    }

    public function down(): void
    {
        Schema::table('network_devices', function (Blueprint $table) {
            $table->dropIndex(['type', 'barangay_id']);
            $table->dropConstrainedForeignId('barangay_id');
            $table->dropColumn(['latitude', 'longitude']);
        });
        Schema::dropIfExists('barangays');
    }
};
