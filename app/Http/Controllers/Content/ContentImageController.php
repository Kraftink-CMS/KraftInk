<?php

namespace App\Http\Controllers\Content;

use App\Http\Controllers\Controller;
use App\Kraft\Content\ContentImages;
use App\Kraft\Media\Images;
use App\Models\Content\ContentImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContentImageController extends Controller
{
    public function store(Request $request, ContentImages $images): JsonResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimetypes:'.implode(',', app(Images::class)->supportedMimeTypes()),
                'max:'.config('community.media_max_kilobytes', 8192),
            ],
        ]);

        $image = $images->store($request->user(), $request->file('file'));

        return response()->json([
            'success' => true,
            'data' => ['location' => route('site.content.images.show', $image), 'id' => $image->id],
        ]);
    }

    public function show(Request $request, ContentImage $image, ContentImages $images): StreamedResponse
    {
        if (!($images->canRead($image, $request->user()) && Storage::disk('local')->exists($image->path))) {
            abort(404);
        }

        return Storage::disk('local')->response($image->path, null, [
            'Content-Type' => $image->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
