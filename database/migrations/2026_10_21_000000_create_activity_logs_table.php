<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What people did in the system, one row per action, each with its own ID:
 * adding, editing and deleting devices, routers, networks, designs, users and
 * barangays; changing settings; generating and sending reports; signing in and out.
 * Opening pages is not logged. The user's name and role are copied in, so the
 * log still reads correctly after the account is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();                                               // Log ID shown on the Logs page
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name', 120)->nullable();               // as it was at the time
            $table->string('user_role', 10)->nullable();
            $table->string('action', 20);                               // created | updated | deleted | generated | sent | settings | signed_in ...
            $table->string('subject_type', 30)->nullable();             // router | access point | switch | network | barangay | design | media | user | report | settings
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label', 190)->nullable();           // its name at the time
            $table->string('description', 500);
            $table->json('changes')->nullable();                        // {field: [old, new]}; secrets masked
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
