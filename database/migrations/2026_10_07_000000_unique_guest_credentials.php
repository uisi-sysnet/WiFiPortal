<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Older test rows shared "mac-..." usernames; make them unique before adding the index.
        $dupes = DB::table('hotspot_guests')->select('username')
            ->groupBy('username')->havingRaw('count(*) > 1')->pluck('username');
        foreach ($dupes as $username) {
            DB::table('hotspot_guests')->where('username', $username)->orderBy('id')->pluck('id')
                ->slice(1) // keep the first one as it is
                ->each(fn ($id) => DB::table('hotspot_guests')->where('id', $id)->update(['username' => $username.'-'.$id]));
        }

        Schema::table('hotspot_guests', function (Blueprint $table) {
            $table->unique('username');
            $table->timestamp('expires_at')->nullable()->index(); // credentials are removed after this
            $table->timestamp('revoked_at')->nullable();          // when they were removed
        });
    }

    public function down(): void
    {
        Schema::table('hotspot_guests', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropIndex(['expires_at']);
            $table->dropColumn(['expires_at', 'revoked_at']);
        });
    }
};
