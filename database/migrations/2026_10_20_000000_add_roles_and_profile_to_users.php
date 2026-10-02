<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * System users: full name (printed on generated reports), position, department,
 * contact, and a role:
 *   admin   full control
 *   user    dashboard, and the Users page with report downloads
 *   viewer  dashboard only
 * Everyone who could sign in before this keeps full control.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('position', 100)->nullable();
            $table->string('department', 120)->nullable();
            $table->string('contact', 100)->nullable();
            $table->string('role', 10)->default('viewer')->index();
            $table->timestamp('last_login_at')->nullable();
        });

        DB::table('users')->update(['role' => 'admin']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn(['position', 'department', 'contact', 'role', 'last_login_at']);
        });
    }
};
