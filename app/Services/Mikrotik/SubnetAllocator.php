<?php

namespace App\Services\Mikrotik;

use App\Models\HotspotNetwork;
use App\Models\MikrotikRouter;
use RuntimeException;

/**
 * Hands out address blocks:
 *   hotspot  one subnet per hotspot network, /16 to /24 (default /HOTSPOT_SITE_PREFIX),
 *            the lowest free one in HOTSPOT_SUPERNET, or any private range the admin types
 *            that overlaps nothing else
 *   mgmt     MGMT_SUPERNET in /MGMT_PREFIX,  one per router  (APs, switches, VLAN)
 *   test     TEST_SUPERNET in /TEST_PREFIX,  one per router  (technicians, VLAN)
 *   lan      LAN_SUPERNET in /LAN_PREFIX,    one per router  (untagged office LAN)
 * Deleted routers and networks free their addresses; the lowest free ones are reused.
 */
class SubnetAllocator
{
    private const PLANS = ['mgmt' => 'MGMT', 'test' => 'TEST', 'lan' => 'LAN'];

    /** Management, test and LAN blocks for a new router. */
    public function next(): array
    {
        $this->assertNoOverlap();
        $used = MikrotikRouter::query()->pluck('block_index')->flip();

        for ($i = 0; $i < $this->blockCount(); $i++) {
            if (! isset($used[$i])) {
                return $this->routerBlock($i);
            }
        }

        throw new RuntimeException(sprintf(
            'The management/test/LAN plans are full (%d routers). Widen MGMT_SUPERNET, TEST_SUPERNET and LAN_SUPERNET.',
            $this->blockCount()
        ));
    }

    /** Largest hotspot network: /16, 65,533 users. */
    public const MIN_PREFIX = 16;

    /** Smallest hotspot network: /24, 254 addresses (253 users plus the gateway). */
    public const MAX_PREFIX = 24;

    /** A user limit, when set, is at least this. */
    public const MIN_USER_LIMIT = 254;

    /** Private ranges a hotspot network may use. */
    private const PRIVATE_RANGES = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10'];

    /** Devices a network of this size can serve: all addresses minus network, broadcast and gateway. */
    public static function capacity(int $prefix): int
    {
        return (1 << (32 - $prefix)) - 3;
    }

    /** @return array<int, string> prefix => "/22, up to 1,021 users", largest first */
    public static function sizeOptions(): array
    {
        $out = [];
        for ($p = self::MIN_PREFIX; $p <= self::MAX_PREFIX; $p++) {
            $out[$p] = "/{$p}, up to ".number_format(self::capacity($p)).' users';
        }

        return $out;
    }

    /** Size new hotspot networks get unless the admin picks another (HOTSPOT_SITE_PREFIX). */
    public function defaultPrefix(): int
    {
        return max(self::MIN_PREFIX, min(self::MAX_PREFIX, $this->sitePrefix()));
    }

    /**
     * The next $count free hotspot subnets of one size, lowest first, with their
     * gateway and DHCP range. With $partial, returns as many as are free instead of failing.
     *
     * @param  string[]  $taken  subnets already promised (e.g. earlier networks in the same form)
     * @return array<int, array{subnet:string,gateway:string,pool_start:string,pool_end:string,max_users:null}>
     */
    public function nextHotspot(int $count = 1, bool $partial = false, ?int $prefix = null, array $taken = []): array
    {
        $out = [];
        try {
            while (count($out) < $count) {
                $subnet = $this->nextHotspotSubnet($prefix, $taken);
                $taken[] = $subnet;
                $out[] = $this->hotspotAddressing($subnet);
            }
        } catch (RuntimeException $e) {
            if (! $partial) {
                throw $e;
            }
        }

        return $out;
    }

    /** Lowest free subnet of $prefix inside HOTSPOT_SUPERNET that overlaps no hotspot network. */
    public function nextHotspotSubnet(?int $prefix = null, array $taken = []): string
    {
        $prefix ??= $this->defaultPrefix();
        $this->assertNoOverlap();
        [$base, $superPrefix] = $this->cidr((string) config('hotspot.supernet'), 'HOTSPOT_SUPERNET');
        if ($prefix < $superPrefix) {
            throw new RuntimeException("A /{$prefix} is larger than HOTSPOT_SUPERNET ".config('hotspot.supernet').'.');
        }

        $size = 1 << (32 - $prefix);
        $end = $base + (1 << (32 - $superPrefix)) - 1;
        $used = array_map(fn ($c) => $this->range($c), [...$this->hotspotSubnets(), ...$taken]);

        for ($candidate = $base; $candidate + $size - 1 <= $end;) {
            $clash = null;
            foreach ($used as $r) {
                if ($r[0] <= $candidate + $size - 1 && $candidate <= $r[1]) {
                    $clash = $r;
                    break;
                }
            }
            if ($clash === null) {
                return long2ip($candidate).'/'.$prefix;
            }
            $candidate = intdiv($clash[1] + $size, $size) * $size; // next aligned block after the clash
        }

        throw new RuntimeException(sprintf(
            'No free /%d left in the hotspot address plan %s. Pick a smaller size, type an address yourself, or widen HOTSPOT_SUPERNET.',
            $prefix, config('hotspot.supernet')
        ));
    }

    /**
     * Checks an address typed by the admin and returns it in canonical form.
     * Refuses sizes outside /16-/24, public ranges, and overlaps with any hotspot
     * network, the management/test/LAN plans, or the portal server itself.
     *
     * @param  string[]  $taken  other subnets in the same form
     */
    public function checkHotspotSubnet(string $input, ?int $exceptNetworkId = null, array $taken = []): string
    {
        if (! preg_match('~^\s*(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})\s*$~', $input, $m) || ip2long($m[1]) === false) {
            throw new RuntimeException('Enter the network address with its size, like 10.70.0.0/22.');
        }
        $prefix = (int) $m[2];
        if ($prefix < self::MIN_PREFIX || $prefix > self::MAX_PREFIX) {
            throw new RuntimeException('Use a size from /'.self::MIN_PREFIX.' ('.number_format(self::capacity(self::MIN_PREFIX)).' users) to /'
                .self::MAX_PREFIX.' ('.number_format(self::capacity(self::MAX_PREFIX)).' users).');
        }
        $ip = ip2long($m[1]);
        $net = $ip & ((~0 << (32 - $prefix)) & 0xFFFFFFFF);
        $cidr = long2ip($net).'/'.$prefix;
        if ($net !== $ip) {
            throw new RuntimeException("{$m[1]} is not the start of a /{$prefix}. Use {$cidr}.");
        }

        $range = $this->range($cidr);
        $inside = fn (array $outer) => $outer[0] <= $range[0] && $range[1] <= $outer[1];
        $overlaps = fn (array $other) => $other[0] <= $range[1] && $range[0] <= $other[1];

        if (! array_filter(self::PRIVATE_RANGES, fn ($p) => $inside($this->range($p)))) {
            throw new RuntimeException('Use a private range: 10.x.x.x, 172.16-31.x.x, 192.168.x.x or 100.64-127.x.x.');
        }
        foreach (self::PLANS as $key => $env) {
            $plan = (string) config("hotspot.{$key}_supernet");
            if ($overlaps($this->range($plan))) {
                $label = ['mgmt' => 'management', 'test' => 'test', 'lan' => 'LAN'][$key];
                throw new RuntimeException("{$cidr} overlaps the {$label} address plan ({$plan}). Choose another range.");
            }
        }
        foreach ($taken as $other) {
            if ($overlaps($this->range($other))) {
                throw new RuntimeException("{$cidr} overlaps {$other}, used by another network in this form.");
            }
        }
        $others = HotspotNetwork::query()->with('router:id,name')
            ->when($exceptNetworkId, fn ($q) => $q->whereKeyNot($exceptNetworkId))->get(['id', 'name', 'subnet', 'mikrotik_router_id']);
        foreach ($others as $n) {
            if ($overlaps($this->range($n->subnet))) {
                throw new RuntimeException("{$cidr} overlaps {$n->name} on {$n->router?->name} ({$n->subnet}).");
            }
        }
        $portal = parse_url((string) config('hotspot.portal_url'), PHP_URL_HOST);
        if ($portal && filter_var($portal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && ip2long($portal) >= $range[0] && ip2long($portal) <= $range[1]) {
            throw new RuntimeException("The portal server {$portal} is inside {$cidr}; phones could not reach it. Choose another range.");
        }

        return $cidr;
    }

    /**
     * Gateway .1, DHCP from .2. With a user limit the DHCP range holds exactly
     * that many addresses; without one it runs to the end of the subnet.
     */
    public function hotspotAddressing(string $subnet, ?int $maxUsers = null): array
    {
        [$net, $prefix] = $this->cidr($subnet, 'Hotspot subnet');
        $last = $net + (1 << (32 - $prefix)) - 2;

        return [
            'subnet' => long2ip($net).'/'.$prefix,
            'gateway' => long2ip($net + 1),
            'pool_start' => long2ip($net + 2),
            'pool_end' => long2ip($maxUsers ? min($last, $net + 1 + $maxUsers) : $last),
            'max_users' => $maxUsers,
        ];
    }

    /** Refuses a user limit below the minimum or above what the subnet holds. */
    public function checkUserLimit(?int $maxUsers, string $subnet): void
    {
        if ($maxUsers === null) {
            return;
        }
        $prefix = (int) explode('/', $subnet)[1];
        $capacity = self::capacity($prefix);
        if ($capacity < self::MIN_USER_LIMIT) {
            throw new RuntimeException("A /{$prefix} holds {$capacity} users, below the smallest limit of ".self::MIN_USER_LIMIT.'. Leave the limit empty (no limit) or choose a larger network.');
        }
        if ($maxUsers < self::MIN_USER_LIMIT || $maxUsers > $capacity) {
            throw new RuntimeException('The user limit must be from '.number_format(self::MIN_USER_LIMIT).' to '.number_format($capacity)." for {$subnet}, or empty for no limit.");
        }
    }

    /** Router block: .1 gateway, lower quarter kept for fixed IPs, DHCP above it. */
    public function routerBlock(int $index): array
    {
        $out = ['block_index' => $index];

        foreach (self::PLANS as $key => $env) {
            [$base, $blockSize, $prefix] = $this->plan($key, $env);
            $n = $base + $index * $blockSize;
            $out["{$key}_subnet"] = long2ip($n).'/'.$prefix;
            $out["{$key}_gateway"] = long2ip($n + 1);
            $out["{$key}_pool_start"] = long2ip($n + max(2, intdiv($blockSize, 4)));
            $out["{$key}_pool_end"] = long2ip($n + $blockSize - 2);
        }

        return $out;
    }

    /** For the plan grid: which /HOTSPOT_SITE_PREFIX cells of the supernet hotspot networks use. */
    public function summary(): array
    {
        $blocks = $this->blockCount();
        $cell = 1 << (32 - $this->sitePrefix());
        [$base] = $this->cidr((string) config('hotspot.supernet'), 'HOTSPOT_SUPERNET');

        $used = [];
        foreach ($this->hotspotSubnets() as $subnet) {
            [$start, $end] = $this->range($subnet);
            $first = max(0, intdiv(max(0, $start - $base), $cell));
            $last = min($blocks - 1, intdiv($end - $base, $cell));
            for ($i = $first; $end >= $base && $i <= $last; $i++) {
                $used[$i] = true;
            }
        }

        return [
            'supernet' => config('hotspot.supernet'),
            'site_prefix' => $this->sitePrefix(),
            'blocks' => $blocks,
            'used_indexes' => array_keys($used),
            'hosts_per_block' => self::capacity($this->sitePrefix()),
            'total_hosts' => $blocks * self::capacity($this->sitePrefix()),
        ];
    }

    /** @return string[] subnets of every hotspot network */
    private function hotspotSubnets(): array
    {
        return HotspotNetwork::query()->pluck('subnet')->all();
    }

    /** @return array{0:int,1:int} first and last address of a CIDR */
    private function range(string $cidr): array
    {
        [$net, $prefix] = $this->cidr($cidr, $cidr);

        return [$net, $net + (1 << (32 - $prefix)) - 1];
    }

    public function blockCount(): int
    {
        [, $superPrefix] = $this->cidr((string) config('hotspot.supernet'), 'HOTSPOT_SUPERNET');

        if ($this->sitePrefix() <= $superPrefix || $this->sitePrefix() > 30) {
            throw new RuntimeException('HOTSPOT_SITE_PREFIX must be longer than the supernet prefix and at most /30.');
        }

        return 1 << ($this->sitePrefix() - $superPrefix);
    }

    /** The address ranges actually handed out by each plan must never overlap. */
    private function assertNoOverlap(): void
    {
        [$hsBase] = $this->cidr((string) config('hotspot.supernet'), 'HOTSPOT_SUPERNET');
        $ranges = ['HOTSPOT' => [$hsBase, $hsBase + $this->blockCount() * (1 << (32 - $this->sitePrefix())) - 1]];

        foreach (self::PLANS as $key => $env) {
            [$base, $blockSize] = $this->plan($key, $env);
            $ranges[$env] = [$base, $base + $this->blockCount() * $blockSize - 1];
        }

        $names = array_keys($ranges);
        foreach ($names as $i => $a) {
            foreach (array_slice($names, $i + 1) as $b) {
                if ($ranges[$a][0] <= $ranges[$b][1] && $ranges[$b][0] <= $ranges[$a][1]) {
                    throw new RuntimeException("{$a}_SUPERNET and {$b}_SUPERNET overlap. Give each plan its own range.");
                }
            }
        }
    }

    /** @return array{0:int,1:int,2:int} base address, block size, block prefix */
    private function plan(string $key, string $env): array
    {
        [$base, $super] = $this->cidr((string) config("hotspot.{$key}_supernet"), "{$env}_SUPERNET");
        $prefix = (int) config("hotspot.{$key}_prefix");

        if ($prefix <= $super || $prefix > 29 || (1 << ($prefix - $super)) < $this->blockCount()) {
            throw new RuntimeException("{$env}_SUPERNET / {$env}_PREFIX must provide at least {$this->blockCount()} blocks.");
        }

        return [$base, 1 << (32 - $prefix), $prefix];
    }

    private function sitePrefix(): int
    {
        return (int) config('hotspot.site_prefix');
    }

    /** @return array{0:int,1:int} network address as integer, prefix length */
    private function cidr(string $cidr, string $setting): array
    {
        [$ip, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
        $long = ip2long((string) $ip);
        $prefix = (int) $prefix;

        if ($long === false || $prefix < 8 || $prefix > 30) {
            throw new RuntimeException("{$setting} \"{$cidr}\" is not a valid IPv4 CIDR between /8 and /30.");
        }

        $mask = (~0 << (32 - $prefix)) & 0xFFFFFFFF;

        return [$long & $mask, $prefix];
    }
}
