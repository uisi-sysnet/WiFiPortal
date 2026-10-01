<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Settings edited on the Settings page, as key => value. Read through a cache
 * (cleared on every change), since the dashboard reads them on each load.
 */
class Setting extends Model
{
    protected $table = 'app_settings';
    protected $primaryKey = 'key';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['key', 'value'];

    private const CACHE = 'app_settings';

    public static function read(string $key, mixed $default = null): mixed
    {
        $all = Cache::rememberForever(self::CACHE, fn () => static::query()->pluck('value', 'key')->all());

        return $all[$key] ?? $default;
    }

    /** Saves several at once; null or "" removes a setting. */
    public static function write(array $values): void
    {
        foreach ($values as $key => $value) {
            if ($value === null || $value === '') {
                static::query()->whereKey($key)->delete();
            } else {
                static::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
            }
        }
        Cache::forget(self::CACHE);
    }
}
