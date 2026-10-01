<?php

namespace App\Jobs;

use App\Models\MikrotikRouter;
use App\Services\Mikrotik\RouterMonitor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Checks a batch of routers for the live dashboard. Unique per batch: when a
 * round is slow (dead routers time out), the next round doesn't pile up behind it.
 */
class PollRouters implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 300;
    public int $uniqueFor = 120;

    /** @param int[] $ids */
    public function __construct(public array $ids)
    {
    }

    public function uniqueId(): string
    {
        return implode(',', $this->ids);
    }

    public function handle(RouterMonitor $monitor): void
    {
        MikrotikRouter::query()->whereIn('id', $this->ids)->each(fn (MikrotikRouter $r) => $monitor->refresh($r));
    }
}
