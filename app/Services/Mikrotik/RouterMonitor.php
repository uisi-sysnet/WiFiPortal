<?php

namespace App\Services\Mikrotik;

use App\Models\HotspotNetwork;
use App\Models\MikrotikRouter;
use Throwable;

/**
 * Live state of a router for the dashboard: does its API answer, and how many
 * hotspot users are logged in (in total and per hotspot network).
 *
 * Like access points and switches, a router that has answered before gets
 * `hotspot.poll.offline_after` missed polls of grace before it shows offline;
 * one that has never answered is offline straight away.
 */
class RouterMonitor
{
    public function __construct(private HotspotProvisioner $api)
    {
    }

    public function refresh(MikrotikRouter $router): MikrotikRouter
    {
        $networks = $router->hotspotNetworks()->get();

        try {
            $users = $this->api->activeUsers($router, $networks->map(fn (HotspotNetwork $n) => $n->serverName())->all());

            $router->forceFill([
                'link_status' => 'online',
                'active_users' => $users['total'],
                'poll_failures' => 0,
                'last_seen_at' => now(),
                'last_polled_at' => now(),
                'poll_error' => null,
            ])->save();
            foreach ($networks as $n) {
                $n->forceFill(['active_users' => $users['servers'][$n->serverName()] ?? 0])->save();
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
                $router->hotspotNetworks()->update(['active_users' => null]);
            }
        }

        return $router;
    }
}
