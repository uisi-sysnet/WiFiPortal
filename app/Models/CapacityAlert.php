<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A router or hotspot network that is busy or full. One open alert per
 * router + network + kind; it is updated while the condition lasts and
 * closed (resolved_at) when it clears. See CapacityMonitor.
 *
 *   users  hotspot users online vs the router's rated capacity  -> add a gateway
 *   cpu    the router's smoothed CPU load                         -> add a gateway
 *   pool   DHCP addresses used in a hotspot network               -> add a VLAN / enlarge it
 */
class CapacityAlert extends Model
{
    protected $fillable = ['mikrotik_router_id', 'hotspot_network_id', 'kind', 'level', 'value', 'message', 'opened_at', 'resolved_at'];

    protected function casts(): array
    {
        return ['value' => 'float', 'opened_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(MikrotikRouter::class, 'mikrotik_router_id');
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(HotspotNetwork::class, 'hotspot_network_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /** Full first, then the highest value. */
    public function scopeWorstFirst(Builder $query): Builder
    {
        return $query->orderByRaw("case when level = 'full' then 0 else 1 end")->orderByDesc('value');
    }

    public function title(): string
    {
        $where = $this->network ? $this->network->name.' on '.$this->router?->name : $this->router?->name;

        return match ($this->kind) {
            'users' => "{$where}: users at ".rtrim(rtrim(number_format($this->value, 1), '0'), '.').'% of capacity',
            'cpu' => "{$where}: CPU at ".rtrim(rtrim(number_format($this->value, 1), '0'), '.').'%',
            default => "{$where}: addresses ".rtrim(rtrim(number_format($this->value, 1), '0'), '.').'% used',
        };
    }
}
