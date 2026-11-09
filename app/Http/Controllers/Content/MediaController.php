<?php

namespace App\Http\Controllers\Content;

use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Media\FileDeletionQueue;
use App\Kraft\Media\Images;
use App\Kraft\Media\MediaSelection;
use App\Kraft\Media\MediaLibrary;
use App\Models\Media\MediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Kraft\TextTranslations;

class MediaController extends Controller
{
    public function selection(Request $request, MediaSelection $selection): JsonResponse
    {
        $mimeTypes = app(Images::class)->supportedMimeTypes();
        $data = $request->validate([
            'context' => 'required|string|max:80',
            'q' => 'nullable|string|max:100',
            'page' => 'sometimes|integer|min:1|max:1000000',
            'mime' => 'sometimes|array|min:1|max:'.count($mimeTypes),
            'mime.*' => [
                'required',
                'string',
                Rule::in($mimeTypes),
            ],
        ]);
        $selection->authorize($request->user(), $data['context']);
        $query = $selection
            ->query($data['context'])
            ->search($data['q'] ?? null)
            ->latest('id');
        if (isset($data['mime'])) {
            $query->whereIn('mime', $data['mime']);
        }
        $assets = $query->paginate(24, [
            'id',
            'original_name',
            'alt',
            'translations',
            'path',
            'width',
            'height',
            'mime',
            'size',
        ]);
        $items = $assets
            ->getCollection()
            ->filter(fn (MediaAsset $asset) => Storage::disk('local')->exists($asset->path))
            ->map(fn (MediaAsset $asset) => $selection->describe($asset))
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $items,
                'pagination' => [
                    'page' => $assets->currentPage(),
                    'current_page' => $assets->currentPage(),
                    'last_page' => $assets->lastPage(),
                    'per_page' => $assets->perPage(),
                    'total' => $assets->total(),
                    'from' => $assets->firstItem(),
                    'to' => $assets->lastItem(),
                ],
            ],
        ]);
    }

    public function storeSelection(Request $request, MediaSelection $selection, MediaLibrary $library): JsonResponse
    {
        $validator = Validator::make($request->all(), ['context' => 'required|string|max:80']);
        $context = $validator->validate()['context'];
        $selection->authorize($request->user(), $context);
        $data = $request->validate([
            'file' => $selection->rules($context),
            'alt' => 'nullable|string|max:255',
        ]);
        $asset = $library->store($request->file('file'), $request->user()->id, $data['alt'] ?? '');

        return response()->json([
            'success' => true,
            'data' => [
                'asset' => $selection->describe($asset),
                'message' => __('site.community.media_uploaded'),
            ],
        ]);
    }

    public function index(Request $request): View
    {
        $data = $request->validate(['q' => 'nullable|string|max:100']);
        $query = MediaAsset::search($data['q'] ?? null)->latest('id');

        return view('admin.media.index', ['assets' => $query->paginate(24)->withQueryString()]);
    }

    public function store(Request $request, MediaLibrary $library, MediaSelection $selection): JsonResponse|RedirectResponse
    {
        $request->validate([
            'file' => $selection->rules('library'),
            'alt' => 'nullable|string|max:255',
        ]);

        $fields = ['alt' => ['max' => 255]];
        $extra = $request->validate(app(TextTranslations::class)->rules($fields));
        $asset = $library->store($request->file('file'), $request->user()->id, $request->string('alt')->toString());
        $asset->update([
            'translations' => app(TextTranslations::class)->normalize(
                $extra['translations'] ?? [],
                $asset->translations ?? [],
                $fields,
            ),
        ]);
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'data' => ['id' => $asset->id, 'location' => route('site.media.show', $asset)],
            ]);
        }

        return back()->with('status', __('site.community.media_uploaded'));
    }

    public function update(Request $request, MediaAsset $asset): RedirectResponse
    {
        $fields = ['alt' => ['max' => 255]];
        $data = $request->validate(['alt' => 'nullable|string|max:255'] + app(TextTranslations::class)->rules($fields));
        $asset->update([
            'alt' => $data['alt'] ?? '',
            'translations' => app(TextTranslations::class)->normalize(
                $data['translations'] ?? [],
                $asset->translations ?? [],
                $fields,
            ),
        ]);

        return back()->with('status', __('site.community.media_saved'));
    }

    public function destroy(MediaAsset $asset, FileDeletionQueue $files): RedirectResponse
    {
        DB::transaction(function () use ($asset, $files) {
            $asset = MediaAsset::query()->lockForUpdate()->findOrFail($asset->id);
            $asset->delete();
            $files->schedule($asset->path, 'media', ['media_assets' => 'path']);
            Audit::record('media.deleted', ['id' => $asset->id]);
        }, 3);

        return back()->with('status', __('site.community.media_deleted'));
    }

    public function show(MediaAsset $asset): StreamedResponse
    {
        if (!Storage::disk('local')->exists($asset->path)) {
            throw new NotFoundHttpException();
        }

        return Storage::disk('local')->response($asset->path, null, [
            'Content-Type' => $asset->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=604800, immutable',
        ]);
    }
}
