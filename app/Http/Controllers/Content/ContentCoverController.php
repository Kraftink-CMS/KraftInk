<?php

namespace App\Http\Controllers\Content;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Http\Controllers\Controller;
use App\Kraft\Content\ContentImages;
use App\Kraft\Media\Images;
use App\Kraft\System\TableQuery;
use App\Models\Content\ContentEntry;
use App\Models\Content\ContentImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContentCoverController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $mimeTypes = app(Images::class)->supportedMimeTypes();
        $data = $request->validate([
            'entry' => 'nullable|integer|min:1|exists:content_entries,id',
            'q' => 'nullable|string|max:100',
            'page' => 'sometimes|integer|min:1|max:1000000',
            'mime' => 'sometimes|array|min:1|max:'.count($mimeTypes),
            'mime.*' => [
                'required',
                'string',
                Rule::in($mimeTypes),
            ],
        ]);
        $entry = !empty($data['entry']) ? ContentEntry::query()->findOrFail($data['entry']) : null;
        $query = ContentImage::query()->whereHas('entries', fn (Builder $entries) => $entries->whereKey($entry?->id));
        if (isset($data['q']) && $data['q'] !== '') {
            TableQuery::whereContains($query, ['original_name'], $data['q']);
        }
        if (isset($data['mime'])) {
            $query->whereIn('mime', $data['mime']);
        }
        $images = $query->latest('created_at')->orderBy('id')->paginate(24);
        $items = $images
            ->getCollection()
            ->filter(fn (ContentImage $image) => Storage::disk('local')->exists($image->path))
            ->map(fn (ContentImage $image) => $this->describe($image))
            ->values();

        return response()
            ->json([
                'success' => true,
                'data' => [
                    'items' => $items,
                    'pagination' => [
                        'page' => $images->currentPage(),
                        'current_page' => $images->currentPage(),
                        'last_page' => $images->lastPage(),
                        'per_page' => $images->perPage(),
                        'total' => $images->total(),
                        'from' => $images->firstItem(),
                        'to' => $images->lastItem(),
                    ],
                ],
            ])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, ContentImages $images): JsonResponse
    {
        $data = $request->validate([
            'entry' => 'nullable|integer|min:1|exists:content_entries,id',
            'file' => [
                'required',
                'file',
                'mimetypes:'.implode(',', app(Images::class)->supportedMimeTypes()),
                'max:'.config('community.media_max_kilobytes', 8192),
            ],
        ]);
        if (!empty($data['entry'])) {
            ContentEntry::query()->findOrFail($data['entry']);
        }
        $image = $images->store($request->user(), $request->file('file'));

        return response()
            ->json(['success' => true, 'data' => ['asset' => $this->describe($image)]])
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $entry): StreamedResponse
    {
        $content = ContentEntry::published()->visibleTo($request->user())->with('privateCover')->findOrFail($entry);
        $image = $content->privateCover;
        if (!($image && Storage::disk('local')->exists($image->path))) {
            throw new NotFoundHttpException();
        }

        return Storage::disk('local')->response($image->path, null, [
            'Content-Type' => $image->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex,nofollow',
        ]);
    }

    private function describe(ContentImage $image): array
    {
        return [
            'id' => $image->id,
            'name' => $image->original_name,
            'url' => route('site.content.images.show', $image),
            'width' => $image->width,
            'height' => $image->height,
            'mime' => $image->mime,
            'size' => $image->size,
        ];
    }
}
