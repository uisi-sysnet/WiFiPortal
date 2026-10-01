<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MikrotikRouter extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_ONLINE = 'online';
    public const STATUS_FAILED = 'failed';

    /**
     * wan     internet uplink (exactly one)
     * lan     untagged office LAN on bridge-lan
     * trunk   tagged port on bridge-trunk carrying every hotspot VLAN, management and test
     * access:<vlan>  untagged port in that hotspot network (plain APs, wireless interfaces).
     *         Plain "access" (saved before networks existed) means the first network.
     * none    left alone
     */
    public const ROLES = ['wan', 'lan', 'trunk', 'access', 'none'];

    /** Router-wide VLANs. Hotspot VLANs belong to each HotspotNetwork. */
    public const NETWORKS = ['mgmt' => 'Management', 'test' => 'Test'];

    protected $fillable = [
        'name', 'location', 'model',
        'host', 'api_port', 'use_ssl', 'username', 'password',
        'port_roles', 'wan_interface', 'wan_mode', 'wan_address', 'wan_gateway',
        'vlans', 'block_index',
        'lan_subnet', 'lan_gateway', 'lan_pool_start', 'lan_pool_end',
        'mgmt_subnet', 'mgmt_gateway', 'mgmt_pool_start', 'mgmt_pool_end',
        'test_subnet', 'test_gateway', 'test_pool_start', 'test_pool_end',
        'identity', 'board_name', 'ros_version',
        'latitude', 'longitude',
    ];

    protected $hidden = ['password'];

    public function hotspotNetworks(): HasMany
    {
        return $this->hasMany(HotspotNetwork::class)->orderBy('id');
    }

    /** "access" or "access:<vlan id>" */
    public static function isAccessRole(string $role): bool
    {
        return $role === 'access' || preg_match('/^access:\d{1,4}$/', $role) === 1;
    }

    /** @return string[] ports untagged in this hotspot network */
    public function accessPortsFor(HotspotNetwork $network): array
    {
        return [
            ...($network->isPrimary() ? $this->portsWith('access') : []),
            ...$this->portsWith('access:'.$network->vlan_id),
        ];
    }

    /** @return string[] every port untagged in some hotspot network */
    public function accessPorts(): array
    {
        return array_keys(array_filter($this->port_roles ?? [], fn ($role) => self::isAccessRole($role)));
    }

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'use_ssl' => 'boolean',
            'api_port' => 'integer',
            'block_index' => 'integer',
            'port_roles' => 'array',
            'vlans' => 'array',
            'provision_log' => 'array',
            'provisioned_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'last_seen_at' => 'datetime',
            'last_polled_at' => 'datetime',
        ];
    }

    /** @return string[] port names that have the given role */
    public function portsWith(string $role): array
    {
        return array_keys($this->port_roles ?? [], $role, true);
    }

    public function hasLan(): bool
    {
        return $this->portsWith('lan') !== [];
    }

    /** @return array{id:int,name:string,native?:bool} */
    public function vlan(string $network): array
    {
        return $this->vlans[$network] ?? config("hotspot.vlans.{$network}");
    }

    public function mgmtNative(): bool
    {
        return (bool) ($this->vlans['mgmt']['native'] ?? false);
    }

    /** Subnet, gateway and pool of a router-wide network (mgmt, test, lan). */
    public function network(string $network): array
    {
        return [
            'subnet' => $this->{"{$network}_subnet"},
            'gateway' => $this->{"{$network}_gateway"},
            'pool_start' => $this->{"{$network}_pool_start"},
            'pool_end' => $this->{"{$network}_pool_end"},
        ];
    }

    public function modelLabel(): string
    {
        return config("mikrotik_models.{$this->model}.label") ?? $this->board_name ?? 'Unknown model';
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
