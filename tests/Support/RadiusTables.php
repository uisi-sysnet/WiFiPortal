<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FreeRADIUS's radpostauth and radacct (created on the server by setup-radius.sh,
 * not by the app's migrations), with the columns the RADIUS page reads.
 */
class RadiusTables
{
    public static function create(bool $stationColumns = true): void
    {
        Schema::create('radpostauth', function (Blueprint $t) use ($stationColumns) {
            $t->id();
            $t->string('username');
            $t->string('pass')->nullable();
            $t->string('reply')->nullable();
            if ($stationColumns) {
                $t->string('calledstationid')->nullable();
                $t->string('callingstationid')->nullable();
            }
            $t->timestamp('authdate')->useCurrent();
        });

        Schema::create('radacct', function (Blueprint $t) {
            $t->bigIncrements('radacctid');
            $t->string('acctsessionid')->default('');
            $t->string('acctuniqueid')->default('');
            $t->string('username')->nullable();
            $t->string('nasipaddress')->default('');
            $t->string('calledstationid')->nullable();
            $t->string('callingstationid')->nullable();
            $t->string('framedipaddress')->nullable();
            $t->timestamp('acctstarttime')->nullable();
            $t->timestamp('acctupdatetime')->nullable();
            $t->timestamp('acctstoptime')->nullable();
            $t->bigInteger('acctsessiontime')->nullable();
            $t->bigInteger('acctinputoctets')->nullable();
            $t->bigInteger('acctoutputoctets')->nullable();
            $t->string('acctterminatecause')->nullable();
        });
    }
}
