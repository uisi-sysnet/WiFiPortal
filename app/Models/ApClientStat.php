<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Clients on one access point during one hour: average (total / samples) and peak. */
class ApClientStat extends Model
{
    public $timestamps = false;

    protected $fillable = ['network_device_id', 'hour', 'samples', 'total', 'peak'];

    protected function casts(): array
    {
        return ['hour' => 'datetime', 'samples' => 'integer', 'total' => 'integer', 'peak' => 'integer'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'network_device_id');
    }

    /** Adds one poll's count to the access point's row for this hour. */
    public static function record(int $deviceId, int $clients, ?Carbon $at = null): void
    {
        $hour = ($at ?? now())->copy()->utc()->startOfHour();
        $clients = max(0, $clients);

        $bump = fn () => static::query()->where('network_device_id', $deviceId)->where('hour', $hour)->update([
            'samples' => DB::raw('samples + 1'),
            'total' => DB::raw('total + '.$clients),
            'peak' => DB::raw("case when peak < {$clients} then {$clients} else peak end"),
        ]);

        if ($bump() === 0) {
            try {
                static::query()->insert(['network_device_id' => $deviceId, 'hour' => $hour, 'samples' => 1, 'total' => $clients, 'peak' => $clients]);
            } catch (QueryException) {
                $bump(); // another worker created the row first
            }
        }
    }
}
