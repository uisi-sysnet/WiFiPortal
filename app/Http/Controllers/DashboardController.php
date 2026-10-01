<?php

namespace App\Http\Controllers;

use App\Models\Barangay;
use App\Models\HotspotGuest;
use App\Models\HotspotNetwork;
use App\Models\MikrotikRouter;
use App\Models\NetworkDevice;
use App\Models\Setting;
use App\Services\Dashboard\UserHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    /** Zoom used with a center from Settings when no zoom is given: about one barangay. */
    public const DEFAULT_ZOOM = 15;

    /**
     * Command-center overview. The top row (users online, routers, switches,
     * access points), the map, the access point grid, busiest barangays and the
     * users chart are live; the event log is still sample() data.
     */
    public function index(UserHistory $history)
    {
        $data = $this->sample();
        $data['chart'] = $history->series('day');
        $data['kpis'] = $this->kpis();
        $data['barangayClients'] = $this->barangayClients();

        return view('dashboard.index', [
            ...$data,
            'mapDevices' => $this->mapDevices(),
            'apGrid' => $this->apGrid(),
            'mapBarangays' => Barangay::query()->whereHas('devices')->orderBy('name')->pluck('name'),
            'mapCenter' => $this->mapCenter(),
        ]);
    }

    /** The map alone, filling the window (opened in a new tab from the dashboard). */
    public function map()
    {
        return view('dashboard.map', [
            'mapDevices' => $this->mapDevices(),
            'mapBarangays' => Barangay::query()->whereHas('devices')->orderBy('name')->pluck('name'),
            'mapCenter' => $this->mapCenter(),
        ]);
    }

    /**
     * Where the map opens. A center saved in Settings is used as is (fixed);
     * without one the map frames every device, falling back to MAP_CENTER_*
     * from .env when nothing is on the map yet.
     */
    private function mapCenter(): array
    {
        $lat = Setting::read('map.latitude');
        $lng = Setting::read('map.longitude');
        $fixed = $lat !== null && $lng !== null;

        return [
            'lat' => $fixed ? (float) $lat : (float) config('devices.map.lat'),
            'lng' => $fixed ? (float) $lng : (float) config('devices.map.lng'),
            'zoom' => (int) ($fixed ? Setting::read('map.zoom', self::DEFAULT_ZOOM) : config('devices.map.zoom')),
            'fixed' => $fixed,
            'carto_key' => config('devices.map.carto_key'),
        ];
    }

    /** The "Users online" chart for one range: day, week, month or year. */
    public function usersChart(Request $request, UserHistory $history)
    {
        $range = $request->validate(['range' => ['required', 'in:'.implode(',', array_keys(UserHistory::RANGES))]])['range'];

        return response()
            ->view('dashboard._chart', ['s' => $history->series($range)])
            ->header('Cache-Control', 'no-store');
    }

    /** The top row, re-rendered for the page's refresh every few seconds. */
    public function live()
    {
        $kpis = $this->kpis();

        return response()->json([
            'html' => view('dashboard._kpis', ['k' => $kpis])->render(),
            'barangays' => view('dashboard._barangays', ['rows' => $this->barangayClients()])->render(),
            'routers' => $kpis['routers'],
            'users' => $kpis['users']['online'],
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Live numbers for the top row.
     *   users     hotspot users logged in, summed over every router that answers (routers:poll)
     *   routers   answering / not answering / not checked yet
     *   switches, aps  from SNMP (devices:poll)
     */
    private function kpis(): array
    {
        $routers = MikrotikRouter::query()
            ->selectRaw("count(*) as total,
                sum(case when link_status = 'online' then 1 else 0 end) as online,
                sum(case when link_status = 'offline' then 1 else 0 end) as offline,
                sum(case when link_status = 'online' then coalesce(active_users, 0) else 0 end) as users,
                max(last_polled_at) as polled")
            ->first();

        $devices = NetworkDevice::query()
            ->selectRaw("type, count(*) as total,
                sum(case when status = 'online' then 1 else 0 end) as online,
                sum(case when status = 'offline' then 1 else 0 end) as offline")
            ->groupBy('type')->get()->keyBy('type');
        $device = fn (string $type) => [
            'total' => (int) ($devices[$type]->total ?? 0),
            'online' => (int) ($devices[$type]->online ?? 0),
            'offline' => (int) ($devices[$type]->offline ?? 0),
        ];

        return [
            'users' => [
                'online' => (int) $routers->users,
                'networks' => HotspotNetwork::query()->where('active_users', '>', 0)->count(),
                'today' => HotspotGuest::query()->where('created_at', '>=', today())->count(),
                'updated' => $routers->polled ? Carbon::parse($routers->polled) : null,
            ],
            'routers' => [
                'total' => (int) $routers->total,
                'online' => (int) $routers->online,
                'offline' => (int) $routers->offline,
            ],
            'switches' => $device('switch'),
            'aps' => $device('ap'),
        ];
    }

    /**
     * Every barangay with the clients connected to its access points, busiest first.
     *   clients    sum over online APs that report a count (null when none do)
     *   reporting  online APs that report a count
     * Counts come from the APs' `clients` column, filled by the SNMP poll.
     *
     * @return array<int, array{name:string, aps:int, online:int, reporting:int, clients:?int}>
     */
    private function barangayClients(): array
    {
        $stats = NetworkDevice::query()
            ->where('type', 'ap')->whereNotNull('barangay_id')
            ->selectRaw("barangay_id, count(*) as aps,
                sum(case when status = 'online' then 1 else 0 end) as online,
                sum(case when status = 'online' and clients is not null then 1 else 0 end) as reporting,
                sum(case when status = 'online' then clients else null end) as clients")
            ->groupBy('barangay_id')->get()->keyBy('barangay_id');

        return Barangay::query()->orderBy('name')->get(['id', 'name'])
            ->map(function (Barangay $b) use ($stats) {
                $s = $stats[$b->id] ?? null;

                return [
                    'name' => $b->name,
                    'aps' => (int) ($s->aps ?? 0),
                    'online' => (int) ($s->online ?? 0),
                    'reporting' => (int) ($s->reporting ?? 0),
                    'clients' => ($s->reporting ?? 0) > 0 ? (int) $s->clients : null,
                ];
            })
            ->sortBy([fn ($a, $b) => ($b['clients'] ?? -1) <=> ($a['clients'] ?? -1), fn ($a, $b) => $a['name'] <=> $b['name']])
            ->values()->all();
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

    /**
     * Everything with a map position, in the shape the map script reads:
     * access points, switches and routers. `key` is "ap:5", "switch:3" or
     * "router:2"; `uplink` is the key of what the device is plugged into
     * (a switch, or a router), drawn as a line when both ends are on the map.
     */
    private function mapDevices(): array
    {
        $devices = NetworkDevice::query()
            ->whereNotNull('network_devices.latitude')
            ->whereNotNull('network_devices.longitude')
            ->leftJoin('barangays', 'barangays.id', '=', 'network_devices.barangay_id')
            ->orderBy('network_devices.name')
            ->get([
                'network_devices.id', 'network_devices.type', 'network_devices.name', 'network_devices.brand', 'network_devices.model',
                'network_devices.host', 'network_devices.status', 'network_devices.latitude', 'network_devices.longitude',
                'network_devices.location', 'network_devices.last_seen_at', 'network_devices.uplink_device_id',
                'network_devices.mikrotik_router_id', 'barangays.name as barangay_name',
            ])
            ->map(fn (NetworkDevice $d) => [
                'key' => $d->type.':'.$d->id,
                'id' => $d->id,
                'type' => $d->type,
                'name' => $d->name,
                'model' => trim($d->brand.' '.$d->model) ?: null,
                'ip' => $d->host,
                'status' => $d->status,
                'lat' => (float) $d->latitude,
                'lng' => (float) $d->longitude,
                'barangay' => $d->barangay_name,
                'landmark' => $d->location,
                'seen' => $d->last_seen_at?->diffForHumans(),
                'uplink' => $d->uplinkValue() ?: null,
                'edit' => route('devices.edit', $d->id),
            ]);

        $routers = MikrotikRouter::query()
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->orderBy('name')
            ->get(['id', 'name', 'location', 'host', 'board_name', 'link_status', 'active_users', 'latitude', 'longitude', 'last_seen_at'])
            ->map(fn (MikrotikRouter $r) => [
                'key' => 'router:'.$r->id,
                'id' => $r->id,
                'type' => 'router',
                'name' => $r->name,
                'model' => $r->board_name,
                'ip' => $r->host,
                'status' => $r->link_status,
                'users' => $r->active_users,
                'lat' => (float) $r->latitude,
                'lng' => (float) $r->longitude,
                'barangay' => null,
                'landmark' => $r->location,
                'seen' => $r->last_seen_at?->diffForHumans(),
                'uplink' => null,
                'edit' => route('routers.show', $r->id),
            ]);

        return [...$routers->all(), ...$devices->all()];
    }

    private function sample(): array
    {
        return [
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
