<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MikrotikRouter extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_ONLINE = 'online';
    public const STATUS_FAILED = 'failed';

    /**
     * wan     internet uplink (exactly one)
     * lan     untagged office LAN on bridge-lan
     * trunk   tagged port on bridge-trunk carrying hotspot, management and test VLANs
     * access  untagged hotspot port on bridge-trunk (plain APs, wireless interfaces)
     * none    left alone
     */
    public const ROLES = ['wan', 'lan', 'trunk', 'access', 'none'];

    public const NETWORKS = ['hotspot' => 'Hotspot', 'mgmt' => 'Management', 'test' => 'Test'];

    protected $fillable = [
        'name', 'location', 'model',
        'host', 'api_port', 'use_ssl', 'username', 'password',
        'port_roles', 'wan_interface', 'wan_mode', 'wan_address', 'wan_gateway',
        'vlans', 'login_mode', 'login_url',
        'block_index', 'subnet', 'gateway', 'pool_start', 'pool_end',
        'lan_subnet', 'lan_gateway', 'lan_pool_start', 'lan_pool_end',
        'mgmt_subnet', 'mgmt_gateway', 'mgmt_pool_start', 'mgmt_pool_end',
        'test_subnet', 'test_gateway', 'test_pool_start', 'test_pool_end',
        'identity', 'board_name', 'ros_version',
    ];

    protected $hidden = ['password'];

    public const LOGIN_MODES = [
        'portal' => 'Captive portal from this system',
        'custom' => 'Custom URL (external portal)',
        'builtin' => "Router's built-in page",
    ];

    protected static function booted(): void
    {
        static::creating(function (self $router) {
            $router->portal_code ??= Str::lower(Str::random(12));
        });
    }

    public function usesExternalLogin(): bool
    {
        return in_array($this->login_mode, ['portal', 'custom'], true);
    }

    /** Where the router's login.html sends people. */
    public function loginTarget(): ?string
    {
        return match ($this->login_mode) {
            'custom' => $this->login_url,
            'portal' => $this->portalUrl(),
            default => null,
        };
    }

    public function portalUrl(): string
    {
        return rtrim((string) config('hotspot.portal_url'), '/').'/portal/'.$this->portal_code;
    }

    /** The redirecting login.html the router downloads during provisioning. */
    public function loginFileUrl(): string
    {
        return rtrim((string) config('hotspot.portal_url'), '/').'/hotspot-files/'.$this->portal_code.'/login.html';
    }

    public function loginModeLabel(): string
    {
        return self::LOGIN_MODES[$this->login_mode] ?? self::LOGIN_MODES['builtin'];
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

    /** Subnet, gateway and pool of one network (hotspot, mgmt, test, lan). */
    public function network(string $network): array
    {
        $p = $network === 'hotspot' ? '' : "{$network}_";

        return [
            'subnet' => $this->{"{$p}subnet"},
            'gateway' => $this->{"{$p}gateway"},
            'pool_start' => $this->{"{$p}pool_start"},
            'pool_end' => $this->{"{$p}pool_end"},
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
