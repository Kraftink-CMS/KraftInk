<?php

namespace App\Http\Controllers\Admin\Content;

use App\Http\Controllers\Controller;
use App\Kraft\Media\MediaSelection;
use App\Kraft\Site\Seo;
use App\Models\Content\ContentEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Uri;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ContentSeoPreviewController extends Controller
{
    public function __invoke(Request $request, Seo $seo, MediaSelection $media): JsonResponse
    {
        $kind = $request->input('kind');
        $data = $request->validate([
            'kind' => ['required', Rule::in(['news', 'page'])],
            'title' => ['nullable', 'string', 'max:200'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'body' => ['nullable', 'string', 'max:1000000'],
            'slug' => [
                'nullable',
                'string',
                'max:160',
                $kind === 'page'
                    ? 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)*$/'
                    : 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            ],
            'locale' => ['required', Rule::in(array_keys(config('kraft.locales')))],
            'cover_media_id' => [
                Rule::excludeIf($request->input('cover_visibility') === 'private'),
                'nullable',
                'integer',
                'min:1',
            ],
            'cover_visibility' => ['sometimes', 'required', Rule::in(['public', 'private'])],
        ]);
        $publicCover = ($data['cover_visibility'] ?? 'public') === 'public';
        $cover =
            $publicCover && !empty($data['cover_media_id'])
                ? $media->selected($data['cover_media_id'], 'content-cover')
                : null;
        if ($publicCover && !empty($data['cover_media_id']) && !$cover) {
            throw ValidationException::withMessages([
                'cover_media_id' => __('validation.exists', ['attribute' => __('site.content.cover')]),
            ]);
        }
        $entry = new ContentEntry([
            'kind' => $data['kind'],
            'locale' => $data['locale'],
            'slug' => $data['slug'] ?? '' ?: 'preview',
            'title' => $data['title'] ?? '',
            'summary' => $data['summary'] ?? '',
            'body' => $data['body'] ?? '',
            'visibility' => 'public',
        ])->setRelation('cover', $cover);
        $path = ($entry->kind === 'page' ? '/p/' : '/news/').$entry->slug;
        $previewRequest = Request::create((string) Uri::of(url('/'))->withPath($path));
        $route = clone Route::getRoutes()->getByName($entry->kind === 'page' ? 'site.pages.show' : 'site.news.show');
        $route->bind($previewRequest);
        $previewRequest->setRouteResolver(fn () => $route);
        $previewRequest->setUserResolver(fn () => $request->user());
        $locale = app()->getLocale();
        try {
            app()->setLocale($data['locale']);
            $metadata = $seo->metadata([], ['seoEntry' => $entry], $previewRequest);
        } finally {
            app()->setLocale($locale);
        }

        return response()
            ->json([
                'success' => true,
                'data' => array_intersect_key($metadata, array_flip(['title', 'description', 'canonical', 'image'])),
            ])
            ->header('Cache-Control', 'private, no-store');
    }
}
