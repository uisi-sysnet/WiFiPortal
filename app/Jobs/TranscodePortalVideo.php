<?php

namespace App\Jobs;

use App\Models\PortalMedia;
use App\Services\Portal\MediaOptimizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class TranscodePortalVideo implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 1000;

    public function __construct(public PortalMedia $media)
    {
    }

    public function handle(MediaOptimizer $optimizer): void
    {
        try {
            $optimizer->transcode($this->media);
        } catch (Throwable $e) {
            $this->media->update(['status' => 'failed', 'error' => $e->getMessage()]);
        }
    }
}
