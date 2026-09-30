<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotspotGuest extends Model
{
    protected $fillable = [
        'mikrotik_router_id', 'resident', 'name', 'contact', 'contact_type',
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
}
