<?php

namespace App\Jobs;

use App\Models\NetworkDevice;
use App\Services\Snmp\SnmpProbe;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollNetworkDevices implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 300;

    /** @param int[] $ids */
    public function __construct(public array $ids)
    {
    }

    public function handle(SnmpProbe $probe): void
    {
        NetworkDevice::query()->whereIn('id', $this->ids)->each(fn (NetworkDevice $d) => $probe->refresh($d));
    }
}
