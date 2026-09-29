<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Page shown after the user's details are saved, with the Connect button.
        Schema::table('splash_pages', function (Blueprint $table) {
            $table->longText('ad_html')->nullable();             // must contain [[connect]]
            $table->string('ad_button_label', 40)->default('Connect');
            $table->unsignedSmallInteger('ad_min_seconds')->default(0); // wait before Connect works
        });

        DB::table('splash_pages')->whereNull('ad_html')
            ->update(['ad_html' => file_get_contents(resource_path('portal/default-ad.html'))]);

        Schema::table('hotspot_guests', function (Blueprint $table) {
            $table->timestamp('connected_at')->nullable();       // when Connect succeeded
            $table->string('login_method', 10)->nullable();      // api | browser
        });
    }

    public function down(): void
    {
        Schema::table('splash_pages', function (Blueprint $table) {
            $table->dropColumn(['ad_html', 'ad_button_label', 'ad_min_seconds']);
        });
        Schema::table('hotspot_guests', function (Blueprint $table) {
            $table->dropColumn(['connected_at', 'login_method']);
        });
    }
};
