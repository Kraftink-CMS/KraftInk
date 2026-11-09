<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kraft\CommunityConnection;
use App\Kraft\LocalePolicy;
use App\Kraft\System\ApiPagination;
use App\Models\Content\ContentEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PublicDataController extends Controller
{
    public function news(Request $request): JsonResponse
    {
        $this->locale($request);
        $perPage = ApiPagination::perPage($request, 15);
        $entries = ContentEntry::published()
            ->visibleTo(null)
            ->where('kind', 'news')
            ->with(['author:id,username', 'cover'])
            ->latest('published_at')
            ->paginate($perPage)
            ->through(fn ($entry) => $this->entry($entry));

        return response()->json(
            ['success' => true, 'data' => ApiPagination::data($entries)],
            headers: ['Cache-Control' => 'public, max-age=60'],
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->locale($request);
        $entry = ContentEntry::published()
            ->visibleTo(null)
            ->where('kind', 'news')
            ->with(['author:id,username', 'cover'])
            ->findOrFail($id);

        return response()->json(
            ['success' => true, 'data' => $this->entry($entry, true)],
            headers: ['Cache-Control' => 'public, max-age=60'],
        );
    }

    public function servers(): JsonResponse
    {
        $this->locale(request());

        return response()->json(
            ['success' => true, 'data' => app(CommunityConnection::class)->data()],
            headers: ['Cache-Control' => 'public, max-age=15', 'Vary' => 'Cookie'],
        );
    }

    private function locale(Request $request): void
    {
        $locale = $request->query('locale', app(LocalePolicy::class)->defaultLocale());
        if (!app(LocalePolicy::class)->valid($locale)) {
            abort(404);
        }
        app()->setLocale($locale);
    }

    private function entry(ContentEntry $entry, bool $full = false): array
    {
        $data = [
            'id' => $entry->id,
            'locale' => $entry->locale,
            'slug' => $entry->slug,
            'title' => $entry->title,
            'summary' => $entry->summary ?:
                Str::limit(trim(html_entity_decode(strip_tags($entry->body), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 300),
            'url' => route('site.news.show', $entry->slug),
            'author' => $entry->author?->username,
            'cover' => !$entry->cover_image_id && $entry->cover
                    ? [
                        'url' => route('site.media.show', $entry->cover),
                        'alt' => $entry->cover->displayText('alt'),
                        'width' => $entry->cover->width,
                        'height' => $entry->cover->height,
                    ]
                    : null,
            'published_at' => $entry->published_at->toAtomString(),
            'updated_at' => $entry->updated_at->toAtomString(),
        ];
        if ($full) {
            $data['body'] = $entry->body;
        }

        return $data;
    }
}
