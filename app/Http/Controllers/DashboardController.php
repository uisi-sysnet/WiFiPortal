<?php

namespace App\Http\Controllers;

use App\Models\Barangay;
use App\Models\NetworkDevice;

class DashboardController extends Controller
{
    /**
     * Command-center overview. Access points, switches and the map are live;
     * routers, users, sites and events are still sample() data.
     */
    public function index()
    {
        $data = $this->sample();

        // Live device counts replace the sample ones.
        $counts = NetworkDevice::query()
            ->selectRaw("type, count(*) as total,
                sum(case when status = 'online' then 1 else 0 end) as online,
                sum(case when status = 'offline' then 1 else 0 end) as offline")
            ->groupBy('type')->get()->keyBy('type');
        foreach (['ap' => 'aps', 'switch' => 'switches'] as $type => $key) {
            $data['kpis'][$key] = [
                'total' => (int) ($counts[$type]->total ?? 0),
                'online' => (int) ($counts[$type]->online ?? 0),
                'offline' => (int) ($counts[$type]->offline ?? 0),
            ];
        }

        return view('dashboard.index', [
            ...$data,
            'mapDevices' => $this->mapDevices(),
            'apGrid' => $this->apGrid(),
            'mapBarangays' => Barangay::query()->whereHas('devices')->orderBy('name')->pluck('name'),
            'mapCenter' => config('devices.map'),
        ]);
    }

    /** JSON for the map's once-a-minute refresh. */
    public function mapData()
    {
        return response()->json([
            'devices' => $this->mapDevices(),
            'updated' => now()->toIso8601String(),
        ]);
    }

    /**
     * Access points grouped by barangay for the "Access points by barangay" panel.
     * clients and utilization stay null until per-vendor collection is added.
     *
     * @return array<int, array{barangay:string, aps:array}>
     */
    private function apGrid(): array
    {
        return NetworkDevice::query()
            ->where('network_devices.type', 'ap')
            ->leftJoin('barangays', 'barangays.id', '=', 'network_devices.barangay_id')
            ->orderByRaw('barangays.name is null, barangays.name')
            ->orderBy('network_devices.name')
            ->get([
                'network_devices.id', 'network_devices.name', 'network_devices.model', 'network_devices.host',
                'network_devices.status', 'network_devices.clients', 'network_devices.utilization',
                'network_devices.location', 'network_devices.last_seen_at', 'barangays.name as barangay_name',
            ])
            ->groupBy(fn (NetworkDevice $d) => $d->barangay_name ?? 'No barangay')
            ->map(fn ($list, $barangay) => [
                'barangay' => $barangay,
                'aps' => $list->map(fn (NetworkDevice $d) => [
                    'name' => $d->name,
                    'model' => $d->model,
                    'ip' => $d->host,
                    'status' => $d->status,
                    'clients' => $d->clients,
                    'utilization' => $d->utilization,
                    'landmark' => $d->location,
                    'seen' => $d->last_seen_at?->diffForHumans(),
                    'edit' => route('devices.edit', $d->id),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /** Every AP and switch that has a map position, in the shape the map script reads. */
    private function mapDevices(): array
    {
        return NetworkDevice::query()
            ->whereNotNull('network_devices.latitude')
            ->whereNotNull('network_devices.longitude')
            ->leftJoin('barangays', 'barangays.id', '=', 'network_devices.barangay_id')
            ->orderBy('network_devices.name')
            ->get([
                'network_devices.id', 'network_devices.type', 'network_devices.name', 'network_devices.model',
                'network_devices.host', 'network_devices.status', 'network_devices.latitude', 'network_devices.longitude',
                'network_devices.location', 'network_devices.last_seen_at', 'barangays.name as barangay_name',
            ])
            ->map(fn (NetworkDevice $d) => [
                'id' => $d->id,
                'type' => $d->type,
                'name' => $d->name,
                'model' => $d->model,
                'ip' => $d->host,
                'status' => $d->status,
                'lat' => (float) $d->latitude,
                'lng' => (float) $d->longitude,
                'barangay' => $d->barangay_name,
                'landmark' => $d->location,
                'seen' => $d->last_seen_at?->diffForHumans(),
                'edit' => route('devices.edit', $d->id),
            ])
            ->all();
    }

    private function sample(): array
    {
        mt_srand(20261001); // same sample on every load

        $barangays = ['Alabang', 'Ayala Alabang', 'Bayanan', 'Buli', 'Cupang', 'Poblacion', 'Putatan', 'Sucat', 'Tunasan'];
        $perBarangay = [18, 10, 16, 9, 15, 17, 16, 15, 12]; // 128 sites

        $sites = [];
        foreach ($barangays as $i => $brgy) {
            $code = strtoupper(substr(str_replace(' ', '', $brgy), 0, 3));
            for ($n = 1; $n <= $perBarangay[$i]; $n++) {
                $roll = mt_rand(1, 100);
                $status = $roll <= 3 ? 'offline' : ($roll <= 7 ? 'degraded' : 'online');
                $sites[] = [
                    'name' => sprintf('%s-%02d', $code, $n),
                    'barangay' => $brgy,
                    'status' => $status,
                    'users' => $status === 'offline' ? 0 : mt_rand(40, 260),
                ];
            }
        }

        // Users online by hour: quiet overnight, lunch bump, evening peak.
        $curve = [22, 14, 9, 7, 6, 9, 21, 42, 58, 63, 66, 71, 82, 76, 70, 68, 72, 81, 93, 100, 97, 88, 64, 40];
        $hourly = array_map(fn ($p) => (int) round($p * 191.6 + mt_rand(-250, 250)), $curve);

        $top = collect($sites)->sortByDesc('users')->take(10)->values()->all();

        return [
            'kpis' => [
                'routers' => ['total' => 128, 'online' => 124],
                'users' => ['online' => 18452, 'today' => 3217, 'peak' => max($hourly)],
                'aps' => ['total' => 1536, 'online' => 1498],
                'switches' => ['total' => 312, 'online' => 309],
            ],
            'sites' => $sites,
            'barangays' => $barangays,
            'hourly' => $hourly,
            'currentHour' => 19,
            'top' => $top,
            'events' => [
                ['time' => '19:42', 'level' => 'down', 'text' => 'Router TUN-07 stopped answering'],
                ['time' => '19:38', 'level' => 'warn', 'text' => 'AP POB-AP-114 dropped to 2.4 GHz only'],
                ['time' => '19:31', 'level' => 'ok', 'text' => 'Router SUC-03 back online after 4 min'],
                ['time' => '19:20', 'level' => 'warn', 'text' => 'Switch BUL-SW-02 port ether7 flapping'],
                ['time' => '19:05', 'level' => 'info', 'text' => 'Evening peak: 18,000 users online'],
                ['time' => '18:47', 'level' => 'down', 'text' => 'AP CUP-AP-031 offline'],
                ['time' => '18:22', 'level' => 'ok', 'text' => 'Splash page updated by admin'],
                ['time' => '17:58', 'level' => 'info', 'text' => 'Router ALA-18 added and configured'],
            ],
        ];
    }
}