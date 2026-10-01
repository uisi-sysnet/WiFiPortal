<?php

namespace App\Services\Dashboard;

use App\Models\MikrotikRouter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * History behind the "Users online" chart.
 *
 * snapshot()  saves the current total (every 5 minutes, `users:snapshot`)
 * series()    peak users per hour, day or month for one of the chart's ranges:
 *               day    last 24 hours, hourly
 *               week   last 7 days, hourly
 *               month  last 30 days, daily
 *               year   last 12 months, monthly
 * Buckets with no snapshot (the system wasn't running) are null, drawn as a gap.
 */
class UserHistory
{
    public const RANGES = [
        'day' => ['label' => 'Day', 'title' => 'last 24 hours', 'step' => 'hour', 'count' => 24],
        'week' => ['label' => 'Week', 'title' => 'last 7 days', 'step' => 'hour', 'count' => 168],
        'month' => ['label' => 'Month', 'title' => 'last 30 days', 'step' => 'day', 'count' => 30],
        'year' => ['label' => 'Year', 'title' => 'last 12 months', 'step' => 'month', 'count' => 12],
    ];

    /** A router poll older than this means the poller stopped: don't record stale numbers. */
    private const FRESH_SECONDS = 180;

    /** Saves the users online now. Returns false (nothing saved) when the router poll isn't running. */
    public function snapshot(): bool
    {
        $routers = MikrotikRouter::query()
            ->selectRaw("sum(case when link_status = 'online' then coalesce(active_users, 0) else 0 end) as users,
                sum(case when link_status = 'online' then 1 else 0 end) as online,
                max(last_polled_at) as polled")
            ->first();

        if (! $routers->polled || Carbon::parse($routers->polled)->lt(now()->subSeconds(self::FRESH_SECONDS))) {
            return false;
        }

        DB::table('user_samples')->insert([
            'recorded_at' => now(),
            'users' => (int) $routers->users,
            'routers_online' => (int) $routers->online,
        ]);
        DB::table('user_samples')->where('recorded_at', '<', now()->subDays((int) config('hotspot.history.keep_days')))->delete();

        return true;
    }

    /**
     * @return array{range:string, title:string, points:array<int, array{at:Carbon, value:?int}>,
     *               peak:?array{at:Carbon, value:int}, now:?int, step:string}
     */
    public function series(string $range): array
    {
        $cfg = self::RANGES[$range] ?? self::RANGES['day'];
        $range = array_key_exists($range, self::RANGES) ? $range : 'day';
        $tz = (string) config('hotspot.history.timezone');
        $stored = (string) config('app.timezone'); // how timestamps are saved
        $step = $cfg['step'];

        // Buckets, oldest first, ending with the current hour/day/month.
        $end = now($tz)->startOf($step);
        $start = $end->copy()->sub($step, $cfg['count'] - 1);
        $format = ['hour' => 'Y-m-d H', 'day' => 'Y-m-d', 'month' => 'Y-m'][$step];

        $peaks = [];
        $latest = null;
        $rows = DB::table('user_samples')
            ->where('recorded_at', '>=', $start->copy()->setTimezone($stored))
            ->orderBy('recorded_at')
            ->select(['recorded_at', 'users'])
            ->cursor();
        foreach ($rows as $row) {
            $key = Carbon::parse($row->recorded_at, $stored)->setTimezone($tz)->format($format);
            $peaks[$key] = max($peaks[$key] ?? 0, (int) $row->users);
            $latest = $row;
        }

        $points = [];
        for ($at = $start->copy(); $at->lte($end); $at->add($step, 1)) {
            $points[] = ['at' => $at->copy(), 'value' => $peaks[$at->format($format)] ?? null];
        }

        $peak = collect($points)->filter(fn ($p) => $p['value'] !== null)->sortByDesc('value')->first();
        $fresh = $latest && Carbon::parse($latest->recorded_at, $stored)->gt(now()->subMinutes(15));

        return [
            'range' => $range,
            'title' => $cfg['title'],
            'step' => $step,
            'points' => $points,
            'peak' => $peak,
            'now' => $fresh ? (int) $latest->users : null,
        ];
    }
}
