<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One thing that happened on the network. Record with SystemEvent::log();
 * Telegram picks up the ones with notify = true (see App\Services\Notify\Notifier).
 */
class SystemEvent extends Model
{
    public const UPDATED_AT = null;

    public const LEVELS = ['down' => 'Down', 'warn' => 'Warning', 'ok' => 'Recovered', 'info' => 'Info'];

    public const KINDS = [
        'router' => 'Routers',
        'ap' => 'Access points',
        'switch' => 'Switches',
        'capacity' => 'Capacity',
        'report' => 'Reports',
        'radius' => 'RADIUS',
    ];

    protected $fillable = ['level', 'kind', 'subject_type', 'subject_id', 'title', 'detail', 'notify', 'notified_at'];

    protected function casts(): array
    {
        return ['notify' => 'boolean', 'notified_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public static function log(string $level, string $kind, string $title, ?string $detail = null, ?Model $subject = null, bool $notify = true): self
    {
        return static::create([
            'level' => $level,
            'kind' => $kind,
            'title' => mb_substr($title, 0, 255),
            'detail' => $detail,
            'subject_type' => $subject ? match (true) {
                $subject instanceof MikrotikRouter => 'router',
                $subject instanceof NetworkDevice => 'device',
                $subject instanceof CapacityAlert => 'capacity',
                default => class_basename($subject),
            } : null,
            'subject_id' => $subject?->getKey(),
            'notify' => $notify,
        ]);
    }

    /** "12 min", "3 h 5 min", "2 d 4 h" */
    public static function duration(?\DateTimeInterface $since): ?string
    {
        if (! $since) {
            return null;
        }
        $minutes = max(1, (int) round((now()->getTimestamp() - $since->getTimestamp()) / 60));

        return match (true) {
            $minutes < 60 => "{$minutes} min",
            $minutes < 1440 => intdiv($minutes, 60).' h'.($minutes % 60 ? ' '.($minutes % 60).' min' : ''),
            default => intdiv($minutes, 1440).' d'.(intdiv($minutes % 1440, 60) ? ' '.intdiv($minutes % 1440, 60).' h' : ''),
        };
    }
}
