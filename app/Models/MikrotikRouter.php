<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MikrotikRouter extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_ONLINE = 'online';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'name', 'location',
        'host', 'api_port', 'use_ssl', 'username', 'password',
        'wan_interface', 'hotspot_interface',
        'block_index', 'subnet', 'gateway', 'pool_start', 'pool_end',
        'identity', 'board_name', 'ros_version',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'use_ssl' => 'boolean',
            'api_port' => 'integer',
            'block_index' => 'integer',
            'provision_log' => 'array',
            'provisioned_at' => 'datetime',
        ];
    }

    public function isBusy(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROVISIONING], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Waiting',
            self::STATUS_PROVISIONING => 'Configuring',
            self::STATUS_ONLINE => 'Configured',
            self::STATUS_FAILED => 'Failed',
            default => ucfirst((string) $this->status),
        };
    }
}
