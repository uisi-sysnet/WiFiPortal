<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened on the network, newest first: routers, access points and switches
 * going down or coming back, capacity alerts, reports sent. Shown on the dashboard
 * and the Logs page, and sent to Telegram (notified_at once handled).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_events', function (Blueprint $table) {
            $table->id();
            $table->string('level', 8);                 // down | warn | ok | info
            $table->string('kind', 16);                 // router | ap | switch | capacity | report | radius
            $table->string('subject_type', 16)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('title', 255);
            $table->text('detail')->nullable();
            $table->boolean('notify')->default(true);   // send to Telegram
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('created_at');
            $table->index(['notify', 'notified_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_events');
    }
};
