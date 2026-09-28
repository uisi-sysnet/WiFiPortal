<?php

namespace App\Jobs;

use App\Models\MikrotikRouter;
use App\Services\Mikrotik\HotspotProvisioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProvisionHotspot implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 180;

    public function __construct(public MikrotikRouter $router)
    {
    }

    public function handle(HotspotProvisioner $provisioner): void
    {
        $this->router->forceFill([
            'status' => MikrotikRouter::STATUS_PROVISIONING,
            'last_error' => null,
        ])->save();

        try {
            $result = $provisioner->provision($this->router);

            $this->router->forceFill([
                ...$result['facts'],
                'status' => MikrotikRouter::STATUS_ONLINE,
                'provision_log' => $result['log'],
                'provisioned_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $this->router->forceFill([
                'status' => MikrotikRouter::STATUS_FAILED,
                'last_error' => $e->getMessage(),
                'provision_log' => $provisioner->log(),
            ])->save();

            report($e);
        }
    }
}
