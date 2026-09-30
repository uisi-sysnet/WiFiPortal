<?php

namespace App\Services\Portal;

use App\Jobs\TranscodePortalVideo;
use App\Models\PortalMedia;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Makes uploads light enough for phones that are not logged in yet (about 1 Mbps).
 *
 * Photos: resized to 1080 px wide, WebP (JPEG if WebP isn't available), quality
 *         stepped down until the file is about 180 KB.
 * Videos: with ffmpeg, re-encoded to 640 px, H.264 at about 500 kbps, max 30 s,
 *         with a small still image and "faststart" so playback starts before the
 *         whole file arrives. Without ffmpeg, only small MP4s are accepted.
 */
class MediaOptimizer
{
    public function image(UploadedFile $file, ?string $alt): PortalMedia
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('Photo optimization needs the PHP gd extension. Add extension=gd to php.ini and restart PHP.');
        }

        $src = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $src) {
            throw new RuntimeException('This file could not be read as a photo. Use JPG, PNG or WebP.');
        }
        $src = $this->upright($src, $file);

        $maxW = (int) config('hotspot.media.image_max_width');
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $maxW / $w);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $webp = function_exists('imagewebp');
        $dst = imagecreatetruecolor($nw, $nh);
        if ($webp) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        } else {
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // JPEG has no transparency
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        $target = (int) config('hotspot.media.image_target_kb') * 1024;
        $bytes = '';
        foreach ([80, 72, 64, 56, 48, 40] as $quality) {
            ob_start();
            $webp ? imagewebp($dst, null, $quality) : imagejpeg($dst, null, $quality);
            $bytes = (string) ob_get_clean();
            if (strlen($bytes) <= $target) {
                break;
            }
        }
        imagedestroy($dst);

        $path = 'portal-media/'.Str::lower(Str::random(24)).($webp ? '.webp' : '.jpg');
        Storage::disk('public')->put($path, $bytes);

        return PortalMedia::create([
            'kind' => 'image', 'original_name' => $file->getClientOriginalName(), 'alt' => $alt,
            'path' => $path, 'bytes' => strlen($bytes), 'width' => $nw, 'height' => $nh, 'status' => 'ready',
        ]);
    }

    public function video(UploadedFile $file, ?string $alt): PortalMedia
    {
        if ($this->ffmpeg()) {
            $source = $file->storeAs('portal-media-src', Str::lower(Str::random(24)).'.'.($file->getClientOriginalExtension() ?: 'bin'), 'local');
            $media = PortalMedia::create([
                'kind' => 'video', 'original_name' => $file->getClientOriginalName(), 'alt' => $alt,
                'source_path' => $source, 'status' => 'processing',
            ]);
            TranscodePortalVideo::dispatch($media);

            return $media;
        }

        $maxMb = (int) config('hotspot.media.video_raw_max_mb');
        if ($file->getMimeType() !== 'video/mp4') {
            throw new RuntimeException('Without ffmpeg on the server, only MP4 videos can be used. Convert it to MP4, or set FFMPEG_PATH so videos are converted automatically.');
        }
        if ($file->getSize() > $maxMb * 1024 * 1024) {
            throw new RuntimeException("This video is ".round($file->getSize() / 1048576, 1)." MB. Without ffmpeg on the server the limit is {$maxMb} MB. Shorten or compress it, or set FFMPEG_PATH so videos are converted automatically.");
        }

        $path = $file->storeAs('portal-media', Str::lower(Str::random(24)).'.mp4', 'public');

        return PortalMedia::create([
            'kind' => 'video', 'original_name' => $file->getClientOriginalName(), 'alt' => $alt,
            'path' => $path, 'bytes' => $file->getSize(), 'status' => 'ready',
        ]);
    }

    /** Runs in the queue: re-encode, make a still, record sizes. */
    public function transcode(PortalMedia $media): void
    {
        $ffmpeg = $this->ffmpeg() ?? throw new RuntimeException('FFMPEG_PATH is not set.');
        $in = Storage::disk('local')->path($media->source_path);
        $base = 'portal-media/'.Str::lower(Str::random(24));
        Storage::disk('public')->makeDirectory('portal-media');
        $out = Storage::disk('public')->path($base.'.mp4');
        $poster = Storage::disk('public')->path($base.'.jpg');

        $video = Process::timeout(900)->run([
            $ffmpeg, '-y', '-i', $in,
            '-t', (string) config('hotspot.media.video_max_seconds'),
            '-vf', "scale='min(640,iw)':-2,fps=24",
            '-c:v', 'libx264', '-profile:v', 'main', '-preset', 'veryfast',
            '-crf', '30', '-maxrate', '450k', '-bufsize', '900k', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '64k', '-ac', '1',
            '-movflags', '+faststart',
            $out,
        ]);
        if (! $video->successful()) {
            throw new RuntimeException('ffmpeg could not convert this video: '.Str::limit(trim($video->errorOutput()), 300));
        }

        Process::timeout(120)->run([$ffmpeg, '-y', '-ss', '1', '-i', $out, '-frames:v', '1', '-q:v', '6', $poster]);
        $size = @getimagesize($poster) ?: [null, null];
        preg_match('/Duration:\s*(\d+):(\d+):([\d.]+)/', $video->errorOutput(), $d);
        $duration = $d ? min((int) config('hotspot.media.video_max_seconds'), $d[1] * 3600 + $d[2] * 60 + (float) $d[3]) : null;

        Storage::disk('local')->delete($media->source_path);
        $media->update([
            'path' => $base.'.mp4',
            'poster_path' => is_file($poster) ? $base.'.jpg' : null,
            'source_path' => null,
            'bytes' => filesize($out),
            'poster_bytes' => is_file($poster) ? filesize($poster) : null,
            'width' => $size[0], 'height' => $size[1],
            'duration' => $duration,
            'status' => 'ready', 'error' => null,
        ]);
    }

    public function ffmpeg(): ?string
    {
        return config('hotspot.media.ffmpeg') ?: null;
    }

    /** Phone photos are often stored sideways with a rotation flag; apply it. */
    private function upright(GdImage $img, UploadedFile $file): GdImage
    {
        if (! function_exists('exif_read_data') || $file->getMimeType() !== 'image/jpeg') {
            return $img;
        }
        $angle = match ((int) (@exif_read_data($file->getRealPath())['Orientation'] ?? 1)) {
            3 => 180, 6 => -90, 8 => 90, default => 0,
        };

        return $angle ? (imagerotate($img, $angle, 0) ?: $img) : $img;
    }
}
