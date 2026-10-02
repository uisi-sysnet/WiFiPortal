<?php

namespace App\Services\Mikrotik;

use App\Models\CapacityAlert;
use App\Models\HotspotNetwork;
use App\Models\MikrotikRouter;

/**
 * Turns a router's latest readings into capacity alerts (run after every poll).
 *
 *   users  users online vs the router's rated users   busy >= 80%, full >= 95%
 *   cpu    smoothed CPU load                           busy >= 85%, full >= 95%
 *   pool   DHCP addresses in use in a hotspot network  busy >= 80%, full >= 95%
 *
 * The advice depends on what is full: a busy router needs another gateway
 * (another VLAN on the same router adds no capacity); a full address pool
 * needs another hotspot network (VLAN) or a larger subnet. An alert closes
 * once the value drops 5 points under the busy line, so it doesn't flicker.
 */
class CapacityMonitor
{
    private const CLEAR_MARGIN = 5;

    public function evaluate(MikrotikRouter $router): void
    {
        $cfg = config('hotspot.capacity');
        $routerBusy = false;

        // Users online vs rated capacity
        $users = $router->usersPercent();
        if ($users !== null) {
            $routerBusy = $users >= $cfg['busy'];
            $this->track($router, null, 'users', $users, $cfg['busy'], $cfg['full'], fn ($level) => sprintf(
                '%s has %s users online, %s%% of the %s it is rated for. Add another gateway router for this area or move some access points to another site. Another VLAN on this router will not add capacity%s.',
                $router->name, number_format($router->active_users), $this->num($users), number_format($router->ratedUsers()),
                $level === 'full' ? '; new users may get slow service or fail to log in' : ''
            ));
        }

        // CPU, smoothed
        if ($router->cpu_avg !== null) {
            $routerBusy = $routerBusy || $router->cpu_avg >= $cfg['cpu_busy'];
            $this->track($router, null, 'cpu', $router->cpu_avg, $cfg['cpu_busy'], $cfg['cpu_full'], fn () => sprintf(
                '%s CPU has averaged %s%% with %s users online. Add another gateway router or move some access points to another site; another VLAN on this router will not help. If users are far below its rating, lower "Rated users" for this router.',
                $router->name, $this->num($router->cpu_avg), number_format((int) $router->active_users)
            ));
        }

        // Address pool per hotspot network
        foreach ($router->hotspotNetworks as $n) {
            $pool = $n->poolPercent();
            if ($pool === null) {
                continue;
            }
            $this->track($router, $n, 'pool', $pool, $cfg['busy'], $cfg['full'], fn () => sprintf(
                '%s (VLAN %d) on %s has handed out %s of its %s addresses (%s%%). %s',
                $n->name, $n->vlan_id, $router->name, number_format($n->leases), number_format($n->poolSize()), $this->num($pool),
                $n->max_users
                    ? 'Raise its user limit, enlarge its network address, or add another hotspot network (VLAN) for new access points.'
                    : 'Enlarge its network address or add another hotspot network (VLAN) for new access points.'
            ).($routerBusy ? ' The router itself is also near its limit, so put the new network on another router.' : ''));
        }
    }

    /** Opens, updates or closes the alert for one router/network/kind. */
    private function track(MikrotikRouter $router, ?HotspotNetwork $network, string $kind, float $value, int $busy, int $full, \Closure $message): void
    {
        $open = CapacityAlert::query()->open()
            ->where('mikrotik_router_id', $router->id)
            ->where('hotspot_network_id', $network?->id)
            ->where('kind', $kind)
            ->first();

        if ($value >= $busy) {
            $level = $value >= $full ? 'full' : 'busy';
            $data = ['level' => $level, 'value' => min(999.9, $value), 'message' => $message($level)];
            $open
                ? $open->update($data)
                : CapacityAlert::create($data + [
                    'mikrotik_router_id' => $router->id,
                    'hotspot_network_id' => $network?->id,
                    'kind' => $kind,
                    'opened_at' => now(),
                ]);

            return;
        }

        if ($open && $value < $busy - self::CLEAR_MARGIN) {
            $open->update(['resolved_at' => now(), 'value' => $value]);
        }
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1), '0'), '.');
    }
}
