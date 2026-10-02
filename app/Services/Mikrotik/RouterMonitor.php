<?php

namespace App\Services\Mikrotik;

use App\Models\HotspotNetwork;
use App\Models\MikrotikRouter;
use Throwable;

/**
 * Live state of a router for the dashboard: does its API answer, how many
 * hotspot users are logged in (in total and per hotspot network), its CPU and
 * memory, and DHCP addresses in use per network. Then checks capacity.
 *
 * Like access points and switches, a router that has answered before gets
 * `hotspot.poll.offline_after` missed polls of grace before it shows offline;
 * one that has never answered is offline straight away.
 */
class RouterMonitor
{
    /** Weight of the newest CPU reading in the smoothed value (about the last 2 minutes at 30 s polls). */
    private const CPU_SMOOTHING = 0.3;

    public function __construct(private HotspotProvisioner $api, private CapacityMonitor $capacity)
    {
    }

    public function refresh(MikrotikRouter $router): MikrotikRouter
    {
        $networks = $router->hotspotNetworks()->get();

        try {
            $h = $this->api->health(
                $router,
                $networks->map(fn (HotspotNetwork $n) => $n->serverName())->all(),
                $networks->map(fn (HotspotNetwork $n) => $n->dhcpServerName())->all(),
            );

            $cpuAvg = $h['cpu'] === null ? null
                : ($router->cpu_avg === null ? (float) $h['cpu'] : round($router->cpu_avg + self::CPU_SMOOTHING * ($h['cpu'] - $router->cpu_avg), 1));

            $router->forceFill([
                'link_status' => 'online',
                'active_users' => $h['total'],
                'cpu_load' => $h['cpu'],
                'cpu_avg' => $cpuAvg,
                'free_memory' => $h['free_memory'],
                'total_memory' => $h['total_memory'],
                'poll_failures' => 0,
                'last_seen_at' => now(),
                'last_polled_at' => now(),
                'poll_error' => null,
            ])->save();
            foreach ($networks as $n) {
                $n->forceFill([
                    'active_users' => $h['servers'][$n->serverName()] ?? 0,
                    'leases' => $h['leases'][$n->dhcpServerName()] ?? 0,
                ])->save();
            }
        } catch (Throwable $e) {
            $failures = (int) $router->poll_failures + 1;
            $offline = $router->last_seen_at === null || $failures >= (int) config('hotspot.poll.offline_after');

            $router->forceFill([
                'link_status' => $offline ? 'offline' : $router->link_status,
                // Unknown while it doesn't answer, so a dead router never counts as "0 users".
                'active_users' => $offline ? null : $router->active_users,
                'poll_failures' => $failures,
                'last_polled_at' => now(),
                'poll_error' => HotspotProvisioner::explain($e, $router),
            ])->save();
            if ($offline) {
                $router->forceFill(['cpu_load' => null, 'cpu_avg' => null])->save();
                $router->hotspotNetworks()->update(['active_users' => null, 'leases' => null]);
            }

            return $router;
        }

        // Busy or full? Opens, updates or closes capacity alerts. Outside the try:
        // a problem here must not count as the router not answering.
        $this->capacity->evaluate($router->setRelation('hotspotNetworks', $networks));

        return $router;
    }
}
