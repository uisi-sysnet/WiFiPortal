<?php

namespace App\Services\Monitoring;

use App\Models\CapacityAlert;
use App\Models\MikrotikRouter;
use App\Models\NetworkDevice;
use App\Models\SystemEvent;
use Illuminate\Support\Carbon;

/**
 * First troubleshooting of what is offline, from the "Connected to" links
 * (access point -> switch -> ... -> router):
 *
 *   - Walk up from each offline device. The highest offline device in the chain is
 *     the ROOT CAUSE; everything offline below it is down because of it.
 *     AP offline, its switch online   -> the AP is the problem: go to the AP.
 *     AP and its switch offline       -> the switch is the problem; the AP follows.
 *     Router offline                  -> everything behind it can't be checked.
 *   - Each root cause gets a first diagnosis and what to check on site.
 *
 * System status:
 *   critical  a router is down, CRITICAL_AP_PERCENT of access points are down, or a router/network is full
 *   warning   any device is down, or a router/network is busy
 *   normal    everything answers
 */
class Troubleshooter
{
    public function analyse(): array
    {
        $tz = (string) config('hotspot.history.timezone');
        $routers = MikrotikRouter::query()->get(['id', 'name', 'location', 'host', 'link_status', 'last_seen_at', 'poll_error']);
        $devices = NetworkDevice::query()->with('barangay:id,name')
            ->get(['id', 'type', 'name', 'brand', 'model', 'host', 'status', 'location', 'barangay_id', 'uplink_device_id', 'mikrotik_router_id', 'last_seen_at', 'last_error']);

        // One node per router and device
        $nodes = [];
        foreach ($routers as $r) {
            $nodes['router:'.$r->id] = [
                'key' => 'router:'.$r->id, 'type' => 'router', 'label' => 'Router', 'name' => $r->name, 'ip' => $r->host,
                'status' => $r->link_status ?: 'unknown', 'where' => (string) $r->location, 'barangay' => null, 'landmark' => $r->location,
                'last_seen' => $r->last_seen_at, 'error' => $r->poll_error, 'parent' => null,
            ];
        }
        foreach ($devices as $d) {
            $nodes['dev:'.$d->id] = [
                'key' => 'dev:'.$d->id, 'type' => $d->type, 'label' => $d->type === 'ap' ? 'Access point' : 'Switch', 'name' => $d->name, 'ip' => $d->host,
                'status' => $d->status ?: 'unknown',
                'where' => trim(implode(' · ', array_filter([$d->barangay?->name, $d->location]))),
                'barangay' => $d->barangay?->name, 'landmark' => $d->location,
                'last_seen' => $d->last_seen_at, 'error' => $d->last_error,
                'parent' => $d->uplink_device_id ? 'dev:'.$d->uplink_device_id : ($d->mikrotik_router_id ? 'router:'.$d->mikrotik_router_id : null),
            ];
        }

        // Root cause of each offline node: climb while the uplink is offline too
        $rootOf = function (string $key) use ($nodes): string {
            $seen = [$key => true];
            while (($p = $nodes[$key]['parent'] ?? null) && isset($nodes[$p]) && ! isset($seen[$p]) && $nodes[$p]['status'] === 'offline') {
                $key = $p;
                $seen[$p] = true;
            }

            return $key;
        };

        $offline = array_filter($nodes, fn ($n) => $n['status'] === 'offline');
        $groups = [];
        foreach ($offline as $key => $n) {
            $groups[$rootOf($key)][] = $key;
        }

        $order = ['router' => 0, 'switch' => 1, 'ap' => 2];
        $roots = [];
        foreach ($groups as $rootKey => $members) {
            $root = $nodes[$rootKey];
            $behind = array_values(array_filter($members, fn ($k) => $k !== $rootKey));
            $behindNodes = array_map(fn ($k) => $nodes[$k], $behind);
            $roots[] = $root + [
                'down_for' => SystemEvent::duration($root['last_seen']),
                'since' => $root['last_seen']?->copy()->setTimezone($tz),
                'uplink' => $root['parent'] ? ($nodes[$root['parent']] ?? null) : null,
                'behind' => $behindNodes,
                'behind_count' => ['switch' => count(array_filter($behindNodes, fn ($n) => $n['type'] === 'switch')),
                    'ap' => count(array_filter($behindNodes, fn ($n) => $n['type'] === 'ap'))],
                'diagnosis' => $this->diagnose($root, $root['parent'] ? ($nodes[$root['parent']] ?? null) : null, count($behind)),
            ];
        }
        // Routers, then switches, then access points; within each, most devices affected, then longest down
        usort($roots, fn ($a, $b) => [$order[$a['type']] ?? 9, -count($a['behind']), $a['last_seen']?->getTimestamp() ?? 0]
            <=> [$order[$b['type']] ?? 9, -count($b['behind']), $b['last_seen']?->getTimestamp() ?? 0]);

        // Every offline device, with what it is waiting on
        $all = [];
        foreach ($offline as $key => $n) {
            $root = $rootOf($key);
            $all[] = $n + [
                'down_for' => SystemEvent::duration($n['last_seen']),
                'cause' => $root === $key ? null : $nodes[$root]['label'].' '.$nodes[$root]['name'],
            ];
        }
        usort($all, fn ($a, $b) => [$order[$a['type']] ?? 9, (string) $a['barangay'], $a['name']] <=> [$order[$b['type']] ?? 9, (string) $b['barangay'], $b['name']]);

        $count = function (string $type) use ($nodes) {
            $list = array_filter($nodes, fn ($n) => $n['type'] === $type);

            return [
                'total' => count($list),
                'online' => count(array_filter($list, fn ($n) => $n['status'] === 'online')),
                'offline' => count(array_filter($list, fn ($n) => $n['status'] === 'offline')),
                'unknown' => count(array_filter($list, fn ($n) => ! in_array($n['status'], ['online', 'offline'], true))),
            ];
        };
        $counts = ['router' => $count('router'), 'switch' => $count('switch'), 'ap' => $count('ap')];
        $capacity = CapacityAlert::query()->open()->worstFirst()->with(['router:id,name', 'network:id,name'])->get();

        return [
            'counts' => $counts,
            'roots' => $roots,
            'offline' => $all,
            'capacity' => $capacity->map(fn ($a) => ['level' => $a->level, 'title' => $a->title(), 'message' => $a->message])->all(),
            'status' => $this->status($counts, $capacity->pluck('level')->all()),
        ];
    }

    /** @return array{level:string, reasons:array<int,string>} */
    private function status(array $c, array $capacityLevels): array
    {
        $apPercent = $c['ap']['total'] ? $c['ap']['offline'] / $c['ap']['total'] * 100 : 0;
        $critical = array_values(array_filter([
            $c['router']['offline'] ? $c['router']['offline'].' '.($c['router']['offline'] === 1 ? 'router is' : 'routers are').' down: every hotspot behind '.($c['router']['offline'] === 1 ? 'it' : 'them').' is offline' : null,
            $apPercent >= (int) config('devices.critical_ap_percent') ? round($apPercent).'% of access points are offline' : null,
            in_array('full', $capacityLevels, true) ? 'a router or hotspot network is full: new users can\'t connect' : null,
        ]));
        if ($critical) {
            return ['level' => 'critical', 'reasons' => $critical];
        }

        $warning = array_values(array_filter([
            $c['switch']['offline'] ? $c['switch']['offline'].' '.($c['switch']['offline'] === 1 ? 'switch' : 'switches').' offline' : null,
            $c['ap']['offline'] ? $c['ap']['offline'].' '.($c['ap']['offline'] === 1 ? 'access point' : 'access points').' offline' : null,
            in_array('busy', $capacityLevels, true) ? 'a router or hotspot network is busy' : null,
        ]));

        return $warning ? ['level' => 'warning', 'reasons' => $warning] : ['level' => 'normal', 'reasons' => ['every router, switch and access point is answering']];
    }

    /** First diagnosis and what to check on site, for one root cause. */
    private function diagnose(array $n, ?array $uplink, int $behind): array
    {
        $what = strtolower($n['label']);
        $affected = $behind ? $behind.' '.($behind === 1 ? 'device' : 'devices').' behind it '.($behind === 1 ? 'is' : 'are').' offline because of it; '.($behind === 1 ? 'it comes' : 'they come').' back with it.' : null;

        if ($n['last_seen'] === null) {
            return [
                'finding' => 'Has never answered since it was added.',
                'steps' => [
                    'Check the IP address ('.$n['ip'].') in the system matches the '.$what.'.',
                    $n['type'] === 'router' ? 'Check the API user, password and port, and that this server may reach it.' : 'Check SNMP is on, the community or v3 user, and that it allows this server\'s IP.',
                    'Check it is powered and cabled.',
                ],
                'affected' => $affected,
            ];
        }

        if ($n['type'] === 'router') {
            return [
                'finding' => 'The router is not answering.',
                'steps' => ['Check power at '.($n['landmark'] ?: 'the site').'.', 'Check the internet (WAN / ISP) link and the link to this server.', 'Check the API service and the router\'s firewall.'],
                'affected' => $affected,
            ];
        }

        if ($uplink === null) {
            return [
                'finding' => 'Offline. Not known what it connects to ("Connected to" is not set).',
                'steps' => ['Check power and the cable at the location.', 'Set "Connected to" on the '.$what.' for a better diagnosis next time.'],
                'affected' => $affected,
            ];
        }

        $up = $uplink['label'].' '.$uplink['name'];
        if ($uplink['status'] === 'online') {
            return [
                'finding' => 'The '.strtolower($uplink['label']).' it connects to ('.$up.') is online, so the problem is at this '.$what.' or its cable.',
                'steps' => $n['type'] === 'ap'
                    ? ['Go to the access point'.($n['landmark'] ? ' ('.$n['landmark'].')' : '').'.', 'Check power: PoE from '.$uplink['name'].' or the adapter.', 'Check the cable to '.$uplink['name'].' and its port.', 'Restart the access point; replace it if it stays off.']
                    : ['Go to the switch'.($n['landmark'] ? ' ('.$n['landmark'].')' : '').'.', 'Check power and the uplink cable from '.$uplink['name'].'.', 'Restart the switch.'],
                'affected' => $affected,
            ];
        }

        return [
            'finding' => $up.' it connects to has not been checked yet, so the cause is not known.',
            'steps' => ['Check power and the cable at the location.'],
            'affected' => $affected,
        ];
    }
}
