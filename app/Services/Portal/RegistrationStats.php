<?php

namespace App\Services\Portal;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Numbers behind the Users page charts: captive portal registrations over a period.
 *
 * One person is identified by their resident ID, else their mobile/email, else
 * their phone's MAC. Within the period:
 *   accumulated  every registration                       (100%)
 *   unique       each person's first registration          (unique + repeated = accumulated)
 *   repeated     every later registration by the same person
 *   returning    how many people registered more than once
 *   average      registrations per hour, day or month
 *   breakdown    visitors vs residents, how many connected, registrations per hotspot network
 *
 * Periods: all (since the first registration: monthly, or yearly after 3 years),
 * year (last 12 months, monthly), month (last 30 days, daily),
 * week (last 7 days, daily), day (last 24 hours, hourly), and a custom date
 * range (hourly up to 2 days, daily up to 62, monthly up to 3 years, then yearly).
 */
class RegistrationStats
{
    public const RANGES = [
        'all' => ['label' => 'All', 'title' => 'All time', 'step' => 'month', 'count' => null, 'per' => 'month'],
        'year' => ['label' => 'Year', 'title' => 'Last 12 months', 'step' => 'month', 'count' => 12, 'per' => 'month'],
        'month' => ['label' => 'Month', 'title' => 'Last 30 days', 'step' => 'day', 'count' => 30, 'per' => 'day'],
        'week' => ['label' => 'Week', 'title' => 'Last 7 days', 'step' => 'day', 'count' => 7, 'per' => 'day'],
        'day' => ['label' => 'Day', 'title' => 'Last 24 hours', 'step' => 'hour', 'count' => 24, 'per' => 'hour'],
    ];

    /** SQL for the person a registration belongs to ("||" works on PostgreSQL and SQLite). */
    private const PERSON = "case
        when resident and citizen_number is not null then 'R:' || citizen_number
        when contact is not null then 'C:' || contact
        else 'M:' || coalesce(mac, username) end";

    /**
     * @param  array{network?:?int, type?:?string}  $filters
     * @param  array{0:string, 1:string}|null  $dates  custom range: first and last day (Y-m-d, inclusive)
     * @return array{range:string, title:string, per:string, bars:array<int, array{at:Carbon, unique:int, repeated:int}>,
     *               accumulated:int, unique:int, repeated:int, returning:int, average:float, peak:?array{at:Carbon, total:int}}
     */
    public function summary(string $range, array $filters = [], ?array $dates = null): array
    {
        $tz = (string) config('hotspot.history.timezone');
        $stored = (string) config('app.timezone');
        $until = null; // upper bound, for a custom range only

        if ($range === 'custom' && $dates) {
            $from = Carbon::parse($dates[0], $tz)->startOfDay();
            $to = Carbon::parse($dates[1], $tz)->startOfDay();
            $days = (int) $from->diffInDays($to) + 1;
            $step = match (true) {
                $days <= 2 => 'hour',
                $days <= 62 => 'day',
                $days <= 1096 => 'month',
                default => 'year',
            };
            $start = $from->copy()->startOf($step);
            $end = $step === 'hour' ? $to->copy()->setTime(23, 0) : $to->copy()->startOf($step);
            $until = $to->copy()->endOfDay();
            $cfg = ['title' => $from->isSameDay($to)
                ? $from->format('F j, Y')
                : $from->format($from->year === $to->year ? 'M j' : 'M j, Y').' to '.$to->format('M j, Y')];
        } else {
            $range = array_key_exists($range, self::RANGES) ? $range : 'month';
            $cfg = self::RANGES[$range];
            $step = $cfg['step'];
        }

        if ($range === 'all') {
            // From the month of the first registration; one bar per year once that is over 3 years.
            $first = DB::table('hotspot_guests')
                ->when($filters['network'] ?? null, fn ($q, $id) => $q->where('hotspot_network_id', $id))
                ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('resident', $type === 'resident'))
                ->min('created_at');
            $from = $first ? Carbon::parse($first, $stored)->setTimezone($tz) : now($tz);
            if ($from->copy()->startOfMonth()->diffInMonths(now($tz)->startOfMonth()) >= 36) {
                $step = 'year';
            }
            $end = now($tz)->startOf($step);
            $start = $from->copy()->startOf($step);
        } elseif ($range !== 'custom') {
            $end = now($tz)->startOf($step);
            $start = $end->copy()->sub($step, $cfg['count'] - 1);
        }
        $format = ['hour' => 'Y-m-d H', 'day' => 'Y-m-d', 'month' => 'Y-m', 'year' => 'Y'][$step];

        $rows = DB::table('hotspot_guests')
            ->where('created_at', '>=', $start->copy()->setTimezone($stored))
            ->when($until, fn ($q) => $q->where('created_at', '<=', $until->copy()->setTimezone($stored)))
            ->when($filters['network'] ?? null, fn ($q, $id) => $q->where('hotspot_network_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('resident', $type === 'resident'))
            ->orderBy('created_at')->orderBy('id')
            ->selectRaw('created_at, resident, connected_at, hotspot_network_id, '.self::PERSON.' as person')
            ->cursor();

        $buckets = [];
        $seen = [];      // person => registrations in the period
        $accumulated = 0;
        $breakdown = ['residents' => 0, 'visitors' => 0, 'connected' => 0, 'networks' => []];
        foreach ($rows as $row) {
            $key = Carbon::parse($row->created_at, $stored)->setTimezone($tz)->format($format);
            $first = ! isset($seen[$row->person]);
            $seen[$row->person] = ($seen[$row->person] ?? 0) + 1;
            $buckets[$key][$first ? 'unique' : 'repeated'] = ($buckets[$key][$first ? 'unique' : 'repeated'] ?? 0) + 1;
            $accumulated++;
            $breakdown[$row->resident ? 'residents' : 'visitors']++;
            if ($row->connected_at !== null) {
                $breakdown['connected']++;
            }
            $net = (int) $row->hotspot_network_id;
            $breakdown['networks'][$net] = ($breakdown['networks'][$net] ?? 0) + 1;
        }

        $bars = [];
        for ($at = $start->copy(); $at->lte($end); $at->add($step, 1)) {
            $b = $buckets[$at->format($format)] ?? [];
            $bars[] = ['at' => $at->copy(), 'unique' => $b['unique'] ?? 0, 'repeated' => $b['repeated'] ?? 0];
        }

        $unique = count($seen);
        $peak = collect($bars)->map(fn ($b) => $b + ['total' => $b['unique'] + $b['repeated']])
            ->filter(fn ($b) => $b['total'] > 0)->sortByDesc('total')->first();

        return [
            'range' => $range,
            'title' => $range === 'all' && $accumulated ? 'All time, since '.$start->format($step === 'year' ? 'Y' : 'F Y') : $cfg['title'],
            'per' => $step,
            'step' => $step,
            'bars' => $bars,
            'accumulated' => $accumulated,
            'unique' => $unique,
            'repeated' => $accumulated - $unique,
            'returning' => count(array_filter($seen, fn ($n) => $n > 1)),
            'average' => $accumulated / max(1, count($bars)),
            'peak' => $peak ? ['at' => $peak['at'], 'total' => $peak['total']] : null,
            // Visitors/residents, how many tapped Connect, and registrations per hotspot network id (0 = removed), busiest first
            'breakdown' => ['networks' => collect($breakdown['networks'])->sortDesc()->all()] + $breakdown,
        ];
    }

    /** "42.5%" of the accumulated total ("0%" when there is nothing yet). */
    public static function percent(int|float $part, int $accumulated): string
    {
        return $accumulated ? rtrim(rtrim(number_format($part / $accumulated * 100, 1), '0'), '.').'%' : '0%';
    }
}
