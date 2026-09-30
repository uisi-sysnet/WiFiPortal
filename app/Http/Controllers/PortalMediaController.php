<?php

namespace App\Http\Controllers;

use App\Models\PortalMedia;
use App\Services\Portal\MediaOptimizer;
use Illuminate\Http\Request;
use Throwable;

/** Photo and video library for the advertisement page (JSON, used by the editor). */
class PortalMediaController extends Controller
{
    public function store(Request $request, MediaOptimizer $optimizer)
    {
        $request->validate([
            'file' => ['required', 'file'],
            'alt' => ['nullable', 'string', 'max:160'],
        ], [
            'file.required' => 'Choose a photo or video first.',
            'file.uploaded' => 'The upload failed. The file may be larger than PHP allows: raise upload_max_filesize and post_max_size in php.ini.',
        ]);

        $file = $request->file('file');
        $mime = (string) $file->getMimeType();
        $cfg = config('hotspot.media');

        try {
            if (str_starts_with($mime, 'image/')) {
                if ($file->getSize() > $cfg['image_upload_max_mb'] * 1048576) {
                    return $this->fail("Photos can be up to {$cfg['image_upload_max_mb']} MB before optimization.");
                }
                $media = $optimizer->image($file, $request->input('alt'));
            } elseif (str_starts_with($mime, 'video/')) {
                if ($file->getSize() > $cfg['video_upload_max_mb'] * 1048576) {
                    return $this->fail("Videos can be up to {$cfg['video_upload_max_mb']} MB before optimization.");
                }
                $media = $optimizer->video($file, $request->input('alt'));
            } else {
                return $this->fail('Upload a photo (JPG, PNG, WebP) or a video (MP4).');
            }
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return response()->json($media->toEditor(), 201);
    }

    /** Polled by the editor while a video is being converted. */
    public function show(PortalMedia $media)
    {
        return response()->json($media->toEditor());
    }

    public function destroy(PortalMedia $media)
    {
        $media->delete();

        return response()->json(['deleted' => true]);
    }

    private function fail(string $message)
    {
        return response()->json(['message' => $message], 422);
    }
}
