<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotspotGuest extends Model
{
    protected $fillable = [
        'mikrotik_router_id', 'hotspot_network_id', 'resident', 'name', 'contact', 'contact_type',
        'citizen_number', 'mac', 'ip', 'username', 'terms_hash',
        'connected_at', 'login_method', 'expires_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return ['resident' => 'boolean', 'connected_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(MikrotikRouter::class, 'mikrotik_router_id');
    }

    /**
     * active   connected, and the login still works
     * waiting  registered, never tapped Connect (login still works)
     * expired  login removed or past its time
     */
    public function state(): string
    {
        if ($this->revoked_at || ($this->expires_at && $this->expires_at->isPast())) {
            return 'expired';
        }

        return $this->connected_at ? 'active' : 'waiting';
    }

    public function stateLabel(): string
    {
        return ['active' => 'Connected', 'waiting' => 'Not connected', 'expired' => 'Expired'][$this->state()];
    }

    /** Resident ID shown to admins as "••••1234". */
    public function maskedCitizenNumber(): ?string
    {
        $n = (string) $this->citizen_number;

        return $n === '' ? null : str_repeat('•', max(0, min(6, strlen($n) - 4))).substr($n, -4);
    }

    /** Query scopes for the Users page filter. */
    public function scopeInState($query, string $state)
    {
        return match ($state) {
            'expired' => $query->where(fn ($q) => $q->whereNotNull('revoked_at')->orWhere('expires_at', '<=', now())),
            'active', 'waiting' => $query->whereNull('revoked_at')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->{$state === 'active' ? 'whereNotNull' : 'whereNull'}('connected_at'),
            default => $query,
        };
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(HotspotNetwork::class, 'hotspot_network_id');
    }
}
