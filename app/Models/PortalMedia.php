<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** A photo or video used on the advertisement page, stored already optimized. */
class PortalMedia extends Model
{
    use LogsActivity;

    public function activityType(): string
    {
        return 'portal photo or video';
    }

    public function activityLabel(): string
    {
        return (string) ($this->original_name ?: '#'.$this->id);
    }

    /** Set while the file is processed, not by anyone. */
    public function activityIgnore(): array
    {
        return ['path', 'poster_path', 'source_path', 'bytes', 'poster_bytes', 'width', 'height', 'duration', 'status', 'error'];
    }

    protected $table = 'portal_media';

    protected $fillable = [
        'kind', 'original_name', 'alt', 'path', 'poster_path', 'source_path',
        'bytes', 'poster_bytes', 'width', 'height', 'duration', 'status', 'error',
    ];

    protected function casts(): array
    {
        return ['bytes' => 'integer', 'poster_bytes' => 'integer', 'width' => 'integer', 'height' => 'integer', 'duration' => 'float'];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $media) {
            Storage::disk('public')->delete(array_filter([$media->path, $media->poster_path]));
            if ($media->source_path) {
                Storage::disk('local')->delete($media->source_path);
            }
        });
    }

    /**
     * Path only (no host), so phones load it from the same address as the page,
     * which is the one already allowed before login.
     */
    public static function publicPath(?string $path): ?string
    {
        return $path ? parse_url(Storage::disk('public')->url($path), PHP_URL_PATH) : null;
    }

    public function token(): string
    {
        return '[[media:'.$this->id.']]';
    }

    /** What a phone downloads before tapping anything: the photo, or the video's still. */
    public function upfrontBytes(): int
    {
        return (int) ($this->kind === 'image' ? $this->bytes : $this->poster_bytes);
    }

    /** HTML that replaces [[media:ID]] on the advertisement page. */
    public function markup(bool $first): string
    {
        $style = 'display:block;width:100%;height:auto;border-radius:12px';
        $size = $this->width && $this->height ? ' width="'.$this->width.'" height="'.$this->height.'"' : '';

        if ($this->kind === 'image') {
            return '<img src="'.e(self::publicPath($this->path)).'" alt="'.e((string) $this->alt).'"'.$size
                .' loading="'.($first ? 'eager' : 'lazy').'" decoding="async" style="'.$style.'">';
        }

        // Nothing downloads until Play is tapped (preload="none"); the still shows meanwhile.
        $poster = $this->poster_path ? ' poster="'.e(self::publicPath($this->poster_path)).'"' : '';

        return '<video controls playsinline preload="none"'.$poster.$size
            .' aria-label="'.e((string) $this->alt).'" style="'.$style.';background:#000;aspect-ratio:'.($this->width && $this->height ? $this->width.'/'.$this->height : '16/9').'">'
            .'<source src="'.e(self::publicPath($this->path)).'" type="video/mp4"></video>';
    }

    public function toEditor(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'name' => $this->original_name,
            'alt' => $this->alt,
            'status' => $this->status,
            'error' => $this->error,
            'bytes' => $this->bytes,
            'upfront' => $this->upfrontBytes(),
            'width' => $this->width,
            'height' => $this->height,
            'duration' => $this->duration,
            'thumb' => self::publicPath($this->kind === 'image' ? $this->path : $this->poster_path),
            'token' => $this->token(),
        ];
    }
}
