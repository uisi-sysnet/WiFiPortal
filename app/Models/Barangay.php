<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Barangay extends Model
{
    use LogsActivity;

    public function activityType(): string
    {
        return 'barangay';
    }

    protected $fillable = ['name'];

    public function devices(): HasMany
    {
        return $this->hasMany(NetworkDevice::class);
    }

    /** Trims and collapses spaces: "  San  Isidro " -> "San Isidro". */
    public static function cleanName(?string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $name));
    }

    /** Case-insensitive duplicate check, optionally ignoring one row (when renaming). */
    public static function nameTaken(string $name, ?int $ignoreId = null): bool
    {
        return static::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }
}
