<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotspot_guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mikrotik_router_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('resident')->default(false);
            $table->string('name', 80)->nullable();
            $table->string('contact', 254)->nullable();
            $table->string('contact_type', 10)->nullable(); // phone | email
            $table->string('citizen_number', 40)->nullable();
            $table->string('mac', 17)->nullable()->index();
            $table->string('ip', 45)->nullable();
            $table->string('username', 64);
            $table->string('terms_hash', 64);               // which T&C version was accepted
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotspot_guests');
    }
};
