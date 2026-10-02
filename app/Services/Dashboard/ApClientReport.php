<?php

namespace App\Services\Dashboard;

use App\Models\NetworkDevice;
use App\Services\Portal\RegistrationStats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Clients per access point over a period, from the hourly log (ap_client_stats)
 * that devices:poll fills. Feeds the Users page table, the report export and
 * the heat map.
 *
 *   average  clients on the AP on average while it reported (per poll, over the period)
 *   peak     the most clients seen at one poll, and the hour it happened
 *   share    the AP's part of all APs' average clients
 *
 * Periods are the Users page's: all, year, month, week, day, or a date range.
 */
class ApClientReport
{
    /**
     * @param  array{0:string, 1:string}|null  $dates  custom range: first and last day (Y-m-d, inclusive)
     * @return array{from:?Carbon, to:Carbon, title:string}
     */
    public function window(string $range, ?array $dates = null): array
    {
        $tz = (string) config('hotspot.history.timezone');
        if ($dates) {
            $from = Carbon::parse($dates[0], $tz)->startOfDay();
            $to = Carbon::parse($dates[1], $tz)->endOfDay();

            return ['from' => $from->utc(), 'to' => $to->utc(), 'title' => $from->copy()->setTimezone($tz)->format('M j, Y').' to '.$to->copy()->setTimezone($tz)->format('M j, Y')];
        }

        return [
            'from' => match ($range) {
                'year' => now()->subMonths(12),
                'month' => now()->subDays(30),
                'week' => now()->subDays(7),
                'day' => now()->subDay(),
                default => null,
            },
            'to' => now(),
            'title' => RegistrationStats::RANGES[$range]['title'] ?? 'All time',
        ];
    }

    /**
     * Every access point with its numbers for the period, busiest first.
     * Access points without data in the period are included with nulls.
     *
     * @return array<int, array{id:int, name:string, barangay:?string, lat:?float, lng:?float, status:string,
     *               now:?int, average:?float, peak:?int, peak_at:?Carbon, hours:int, share:float}>
     */
    public function perAp(array $window, ?int $barangayId = null): array
    {
        $agg = $this->stats($window)
            ->groupBy('network_device_id')
            ->selectRaw('network_device_id, sum(samples) as samples, sum(total) as total, max(peak) as peak, count(*) as hours')
            ->get()->keyBy('network_device_id');

        // The hour each AP hit its peak (the latest, if it did more than once)
        $peakAt = $this->stats($window, 's')
            ->joinSub($this->stats($window)->groupBy('network_device_id')->selectRaw('network_device_id, max(peak) as p'), 'm',
                fn ($j) => $j->on('m.network_device_id', '=', 's.network_device_id')->on('m.p', '=', 's.peak'))
            ->groupBy('s.network_device_id')
            ->selectRaw('s.network_device_id, max(s.hour) as hour')
            ->pluck('hour', 'network_device_id');

        $rows = NetworkDevice::query()
            ->where('network_devices.type', 'ap')
            ->when($barangayId, fn ($q) => $q->where('network_devices.barangay_id', $barangayId))
            ->leftJoin('barangays', 'barangays.id', '=', 'network_devices.barangay_id')
            ->get(['network_devices.id', 'network_devices.name', 'network_devices.status', 'network_devices.clients',
                'network_devices.latitude', 'network_devices.longitude', 'network_devices.location', 'barangays.name as barangay_name'])
            ->map(function (NetworkDevice $d) use ($agg, $peakAt) {
                $a = $agg->get($d->id);
                $samples = (int) ($a->samples ?? 0);

                return [
                    'id' => $d->id,
                    'name' => $d->name,
                    'barangay' => $d->barangay_name,
                    'landmark' => $d->location,
                    'lat' => $d->latitude !== null ? (float) $d->latitude : null,
                    'lng' => $d->longitude !== null ? (float) $d->longitude : null,
                    'status' => $d->status,
                    'now' => $d->status === 'online' ? $d->clients : null,
                    'average' => $samples ? round($a->total / $samples, 1) : null,
                    'peak' => $samples ? (int) $a->peak : null,
                    'peak_at' => isset($peakAt[$d->id]) ? Carbon::parse($peakAt[$d->id], 'UTC') : null,
                    'hours' => (int) ($a->hours ?? 0),
                ];
            });

        $sum = $rows->sum(fn ($r) => $r['average'] ?? 0);

        return $rows
            ->map(fn ($r) => $r + ['share' => $sum > 0 && $r['average'] ? $r['average'] / $sum * 100 : 0.0])
            ->sort(fn ($a, $b) => [$b['average'] ?? -1, $b['peak'] ?? -1, $a['name']] <=> [$a['average'] ?? -1, $a['peak'] ?? -1, $b['name']])
            ->values()->all();
    }

    /**
     * Totals for the period: APs reporting, clients on an average hour, and the
     * busiest hour across every AP.
     *
     * @return array{aps:int, reporting:int, average:float, per_ap:float, busiest:?array{at:Carbon, clients:int}, top:?array}
     */
    public function totals(array $window, array $perAp): array
    {
        $reporting = array_values(array_filter($perAp, fn ($r) => $r['average'] !== null));
        $busiest = $this->stats($window)
            ->groupBy('hour')
            ->selectRaw('hour, sum(total * 1.0 / samples) as clients')
            ->orderByDesc('clients')->first();

        $avg = array_sum(array_column($reporting, 'average'));

        return [
            'aps' => count($perAp),
            'reporting' => count($reporting),
            'average' => round($avg, 1),
            'per_ap' => $reporting ? round($avg / count($reporting), 1) : 0.0,
            'busiest' => $busiest ? ['at' => Carbon::parse($busiest->hour, 'UTC'), 'clients' => (int) round($busiest->clients)] : null,
            'top' => $reporting[0] ?? null,
        ];
    }

    /**
     * Heat map points: [lat, lng, weight, name, barangay] for access points with a
     * map position. metric: average or peak over the period, or now (live count).
     *
     * @return array<int, array{lat:float, lng:float, value:float, name:string, barangay:?string}>
     */
    public function heatPoints(array $perAp, string $metric): array
    {
        return collect($perAp)
            ->filter(fn ($r) => $r['lat'] !== null && $r['lng'] !== null)
            ->map(fn ($r) => [
                'lat' => $r['lat'],
                'lng' => $r['lng'],
                'value' => (float) (match ($metric) { 'peak' => $r['peak'], 'now' => $r['now'], default => $r['average'] } ?? 0),
                'name' => $r['name'],
                'barangay' => $r['barangay'],
                'landmark' => $r['landmark'],
            ])
            ->values()->all();
    }

    /** Average clients per barangay (sum over its APs), busiest first. */
    public function perBarangay(array $perAp, string $metric = 'average'): array
    {
        $key = $metric === 'now' ? 'now' : ($metric === 'peak' ? 'peak' : 'average');

        return collect($perAp)
            ->groupBy(fn ($r) => $r['barangay'] ?? 'No barangay')
            ->map(fn ($list, $name) => ['name' => $name, 'aps' => $list->count(), 'value' => round($list->sum(fn ($r) => $r[$key] ?? 0), 1)])
            ->sortByDesc('value')->values()->all();
    }

    /** Deletes hourly rows older than AP_CLIENT_STATS_DAYS. */
    public function prune(): int
    {
        return DB::table('ap_client_stats')->where('hour', '<', now()->subDays((int) config('devices.client_stats_days')))->delete();
    }

    private function stats(array $window, string $alias = 'ap_client_stats')
    {
        $q = DB::table('ap_client_stats'.($alias !== 'ap_client_stats' ? " as {$alias}" : ''));

        return $q->when($window['from'], fn ($q, $from) => $q->where("{$alias}.hour", '>=', $from->copy()->utc()->startOfHour()))
            ->where("{$alias}.hour", '<=', $window['to']->copy()->utc());
    }
}
