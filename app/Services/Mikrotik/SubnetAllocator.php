<?php

namespace App\Services\Mikrotik;

use App\Models\MikrotikRouter;
use RuntimeException;

/**
 * Gives every router block N of each address plan:
 *   hotspot  HOTSPOT_SUPERNET in /HOTSPOT_SITE_PREFIX  (public users, VLAN)
 *   mgmt     MGMT_SUPERNET in /MGMT_PREFIX            (APs, switches, VLAN)
 *   test     TEST_SUPERNET in /TEST_PREFIX            (technicians, VLAN)
 *   lan      LAN_SUPERNET in /LAN_PREFIX              (untagged office LAN)
 * Deleted routers free their block and the lowest free block is reused.
 */
class SubnetAllocator
{
    private const PLANS = ['mgmt' => 'MGMT', 'test' => 'TEST', 'lan' => 'LAN'];

    public function next(): array
    {
        $this->assertNoOverlap();
        $used = MikrotikRouter::query()->pluck('block_index')->flip();

        for ($i = 0; $i < $this->blockCount(); $i++) {
            if (! isset($used[$i])) {
                return $this->block($i);
            }
        }

        throw new RuntimeException(sprintf(
            'The address plan %s is full (%d blocks of /%d). Widen HOTSPOT_SUPERNET or use a smaller HOTSPOT_SITE_PREFIX.',
            config('hotspot.supernet'), $this->blockCount(), $this->sitePrefix()
        ));
    }

    public function block(int $index): array
    {
        [$hsBase] = $this->cidr((string) config('hotspot.supernet'), 'HOTSPOT_SUPERNET');
        $size = 1 << (32 - $this->sitePrefix());
        $net = $hsBase + $index * $size;

        // Hotspot: .1 gateway, everything else in the pool
        $out = [
            'block_index' => $index,
            'subnet' => long2ip($net).'/'.$this->sitePrefix(),
            'gateway' => long2ip($net + 1),
            'pool_start' => long2ip($net + 2),
            'pool_end' => long2ip($net + $size - 2),
        ];

        // Small plans: .1 gateway, lower quarter kept for fixed IPs, DHCP above it
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

    public function summary(): array
    {
        $blocks = $this->blockCount();
        $perBlock = (1 << (32 - $this->sitePrefix())) - 3; // network, gateway, broadcast

        return [
            'supernet' => config('hotspot.supernet'),
            'site_prefix' => $this->sitePrefix(),
            'blocks' => $blocks,
            'used_indexes' => MikrotikRouter::query()->pluck('block_index')->all(),
            'hosts_per_block' => $perBlock,
            'total_hosts' => $blocks * $perBlock,
        ];
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
