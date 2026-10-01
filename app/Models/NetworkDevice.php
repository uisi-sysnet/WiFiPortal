<?php

namespace App\Models;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NetworkDevice extends Model
{
    public const TYPES = [
        'ap' => ['label' => 'Access point', 'plural' => 'Access points', 'route' => 'aps'],
        'switch' => ['label' => 'Switch', 'plural' => 'Switches', 'route' => 'switches'],
    ];

    protected $fillable = [
        'type', 'name', 'brand', 'model', 'mac_address', 'serial_number', 'firmware_version',
        'mikrotik_router_id', 'uplink_device_id', 'barangay_id', 'location', 'latitude', 'longitude',
        'deployed_at', 'warranty', 'host', 'snmp_port', 'snmp_version', 'community',
        'v3_username', 'v3_security_level', 'v3_auth_protocol', 'v3_auth_password',
        'v3_priv_protocol', 'v3_priv_password',
    ];

    protected $hidden = ['community', 'v3_auth_password', 'v3_priv_password'];

    /** Match the column defaults, so a device just created already has them. */
    protected $attributes = [
        'status' => 'unknown',
        'failures' => 0,
    ];

    protected function casts(): array
    {
        return [
            'community' => 'encrypted',
            'v3_auth_password' => 'encrypted',
            'v3_priv_password' => 'encrypted',
            'snmp_port' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'uptime_seconds' => 'integer',
            'clients' => 'integer',
            'utilization' => 'integer',
            'failures' => 'integer',
            'deployed_at' => 'date',  
            'last_seen_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(MikrotikRouter::class, 'mikrotik_router_id');
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    /** The switch this device is plugged into (null when it goes straight to a router, or isn't set). */
    public function uplink(): BelongsTo
    {
        return $this->belongsTo(self::class, 'uplink_device_id');
    }

    /** Devices plugged into this switch. */
    public function downlinks(): HasMany
    {
        return $this->hasMany(self::class, 'uplink_device_id');
    }

    /** The "Connected to" choice as the form sends it: "switch:12", "router:3" or "". */
    public function uplinkValue(): string
    {
        return match (true) {
            $this->uplink_device_id !== null => 'switch:'.$this->uplink_device_id,
            $this->mikrotik_router_id !== null => 'router:'.$this->mikrotik_router_id,
            default => '',
        };
    }

    /**
     * Ids of every device downstream of this one (its downlinks, theirs, ...).
     * A switch can't be cascaded from any of these, or the chain would loop.
     *
     * @return int[]
     */
    public function downstreamIds(): array
    {
        $found = [];
        $frontier = [$this->id];
        while ($frontier) {
            $next = self::query()->whereIn('uplink_device_id', $frontier)->whereNotIn('id', $found)->pluck('id')->all();
            $found = [...$found, ...$next];
            $frontier = $next;
        }

        return $found;
    }

    /** Devices behind this switch belong to the same site router; keep that in step after a change. */
    public function propagateSiteRouter(): void
    {
        if ($ids = $this->downstreamIds()) {
            self::query()->whereIn('id', $ids)->update(['mikrotik_router_id' => $this->mikrotik_router_id]);
        }
    }

    /**
     * Any common MAC format ("aa-bb-cc-dd-ee-ff", "aabb.ccdd.eeff", "0:c:29:a:b:c",
     * or 6 raw bytes from SNMP) to "AA:BB:CC:DD:EE:FF". Null if it isn't a MAC.
     */
    public static function normalizeMac(?string $value, bool $allowBinary = false): ?string
    {
        $value = trim((string) $value, " \"\t\r\n");

        if ($allowBinary && strlen($value) === 6 && ! ctype_print($value)) {
            $hex = bin2hex($value);
        } elseif (preg_match('/^([0-9a-f]{1,2}[:\- ]){5}[0-9a-f]{1,2}$/i', $value)) {
            $hex = implode('', array_map(fn ($p) => str_pad($p, 2, '0', STR_PAD_LEFT), preg_split('/[:\- ]/', $value)));
        } else {
            $hex = preg_replace('/[^0-9a-f]/i', '', $value);
        }

        return strlen($hex) === 12 ? strtoupper(implode(':', str_split($hex, 2))) : null;
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function mapUrl(): ?string
    {
        return $this->hasCoordinates()
            ? 'https://www.google.com/maps?q='.(float) $this->latitude.','.(float) $this->longitude
            : null;
    }

    public static function typeInfo(string $type): array
    {
        return self::TYPES[$type] ?? abort(404);
    }

    public function info(): array
    {
        return self::typeInfo($this->type);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'online' => 'Online',
            'offline' => 'Offline',
            default => 'Not checked yet',
        };
    }

    public function uptimeLabel(): ?string
    {
        return $this->uptime_seconds === null ? null
            : CarbonInterval::seconds($this->uptime_seconds)->cascade()->forHumans(['short' => true, 'parts' => 2]);
    }
}
