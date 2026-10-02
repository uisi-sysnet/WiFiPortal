<?php

namespace App\Services\Portal;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * How long internet access lasts after registering, per type of user, set on
 * the Settings page (stored as "12h" or "7d"). Until then the same login works
 * on every router and hotspot network without the captive portal; afterwards
 * the person registers again. Default: HOTSPOT_CREDENTIAL_HOURS.
 */
class AccessValidity
{
    public const CATEGORIES = [
        'resident' => 'Residents (citizens)',
        'visitor' => 'Non-residents (visitors)',
        'student' => 'Students',
    ];

    public const UNITS = ['hours' => 'hours', 'days' => 'days'];

    /** Longest allowed: a year. */
    public const MAX_HOURS = 8760;

    /** @return array{amount:int, unit:string} */
    public function get(string $category): array
    {
        $raw = (string) Setting::read("validity.{$category}", '');
        if (preg_match('/^(\d+)([hd])$/', $raw, $m)) {
            return ['amount' => (int) $m[1], 'unit' => $m[2] === 'd' ? 'days' : 'hours'];
        }

        return ['amount' => (int) config('hotspot.credential_hours'), 'unit' => 'hours'];
    }

    /** Saves "12 hours" / "7 days" for a category. */
    public function set(string $category, int $amount, string $unit): void
    {
        Setting::write(["validity.{$category}" => $amount.($unit === 'days' ? 'd' : 'h')]);
    }

    public function hours(string $category): int
    {
        $v = $this->get($category);

        return $v['unit'] === 'days' ? $v['amount'] * 24 : $v['amount'];
    }

    /** When a registration made now by this type of user stops working. */
    public function expiresAt(string $category): Carbon
    {
        $v = $this->get($category);

        return $v['unit'] === 'days' ? now()->addDays($v['amount']) : now()->addHours($v['amount']);
    }

    /** "7 days", "1 hour" */
    public function label(string $category): string
    {
        $v = $this->get($category);
        $unit = $v['amount'] === 1 ? rtrim($v['unit'], 's') : $v['unit'];

        return number_format($v['amount']).' '.$unit;
    }
}
