<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mikrotik_routers', function (Blueprint $table) {
            // builtin | portal (this app's splash page) | custom (external URL)
            $table->string('login_mode', 10)->default('builtin');
            $table->string('login_url')->nullable();
            // Public, unguessable id used in splash page URLs
            $table->string('portal_code', 16)->nullable()->unique();
        });

        foreach (DB::table('mikrotik_routers')->whereNull('portal_code')->pluck('id') as $id) {
            DB::table('mikrotik_routers')->where('id', $id)->update(['portal_code' => Str::lower(Str::random(12))]);
        }
    }

    public function down(): void
    {
        Schema::table('mikrotik_routers', function (Blueprint $table) {
            $table->dropUnique(['portal_code']);
            $table->dropColumn(['login_mode', 'login_url', 'portal_code']);
        });
    }
};
