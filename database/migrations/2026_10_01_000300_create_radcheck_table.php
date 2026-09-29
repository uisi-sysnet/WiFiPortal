<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * FreeRADIUS reads guest passwords from radcheck. Same columns as the
 * FreeRADIUS SQL schema, so FreeRADIUS can use this table as-is.
 * Skipped if you already loaded the FreeRADIUS schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('radcheck')) {
            return;
        }

        Schema::create('radcheck', function (Blueprint $table) {
            $table->increments('id');
            $table->string('username', 64)->default('')->index();
            $table->string('attribute', 64)->default('');
            $table->string('op', 2)->default('==');
            $table->string('value', 253)->default('');
        });
    }

    public function down(): void
    {
        // Left in place on purpose: FreeRADIUS may depend on it.
    }
};
