<?php

namespace App\Services\Mikrotik;

use App\Models\MikrotikRouter;
use RuntimeException;

/**
 * Carves one client subnet per gateway out of the configured supernet.
 * Deleted routers free their block, and the lowest free block is reused.
 */
class SubnetAllocator
{
    public function next(): array
    {
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
        [$base] = $this->supernet();
        $size = $this->blockSize();
        $network = $base + ($index * $size);
        $broadcast = $network + $size - 1;

        return [
            'block_index' => $index,
            'subnet' => long2ip($network).'/'.$this->sitePrefix(),
            'gateway' => long2ip($network + 1),
            'pool_start' => long2ip($network + 2),
            'pool_end' => long2ip($broadcast - 1),
        ];
    }

    public function summary(): array
    {
        $blocks = $this->blockCount();
        $perBlock = $this->blockSize() - 3; // network, gateway, broadcast

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
        [, $superPrefix] = $this->supernet();

        return 1 << ($this->sitePrefix() - $superPrefix);
    }

    private function blockSize(): int
    {
        return 1 << (32 - $this->sitePrefix());
    }

    private function sitePrefix(): int
    {
        return (int) config('hotspot.site_prefix');
    }

    /** @return array{0:int,1:int} network address as integer, prefix length */
    private function supernet(): array
    {
        $cidr = (string) config('hotspot.supernet');
        [$ip, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
        $long = ip2long((string) $ip);
        $prefix = (int) $prefix;

        if ($long === false || $prefix < 8 || $prefix > 30) {
            throw new RuntimeException("HOTSPOT_SUPERNET \"{$cidr}\" is not a valid IPv4 CIDR between /8 and /30.");
        }
        if ($this->sitePrefix() <= $prefix || $this->sitePrefix() > 30) {
            throw new RuntimeException('HOTSPOT_SITE_PREFIX must be longer than the supernet prefix and at most /30.');
        }

        $mask = (~0 << (32 - $prefix)) & 0xFFFFFFFF;

        return [$long & $mask, $prefix];
    }
}
