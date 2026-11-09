<?php

namespace App\Http\Controllers\Admin\Appearance;

use App\Kraft\System\TableQuery;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Media\MediaLibrary;
use App\Kraft\Media\MediaSelection;
use App\Kraft\Slider;
use App\Kraft\TextTranslations;
use App\Models\Media\MediaAsset;
use App\Models\Site\Slide;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use App\Kraft\SiteDates;

class SliderController extends Controller
{
    public function index(): View
    {
        return view('admin.slides.index', [
            'slides' => TableQuery::paginate(
                Slide::with('media')->orderBy('position')->orderBy('id'),
                ['title', 'locale', 'position'],
                search: ['title', 'alt'],
            ),
        ]);
    }

    public function edit(?Slide $slide = null): View
    {
        $selectedId = old('media_asset_id', $slide?->media_asset_id);
        $selected = is_numeric($selectedId)
            ? MediaAsset::query()->whereIn('mime', Slider::IMAGE_TYPES)->find((int) $selectedId)
            : null;

        return view('admin.slides.edit', [
            'slide' => $slide,
            'selectedAsset' => $selected,
        ]);
    }

    public function store(
        Request $request,
        MediaLibrary $library,
        MediaSelection $selection,
        ?Slide $slide = null,
    ): RedirectResponse {
        app(SiteDates::class)->normalize($request, ['starts_at', 'ends_at']);
        $translations = app(TextTranslations::class);
        $data = $request->validate([
            'title' => 'nullable|string|max:160',
            'description' => 'nullable|string|max:2000',
            'alt' => 'required|string|max:255',
            'media_asset_id' => 'nullable|integer|exists:media_assets,id',
            'file' => $selection->rules('slide', required: false),
            'url' => [
                'nullable',
                'required_with:link_label',
                'string',
                'max:1000',
                function ($attribute, $value, $fail) {
                    if (!Slider::validUrl($value)) {
                        $fail(__('admin.slides.invalid_url'));
                    }
                },
            ],
            'link_label' => 'nullable|required_with:url|string|max:100',
            'locale' => ['nullable', Rule::in(array_keys(config('kraft.locales')))],
            'position' => 'required|integer|between:0,9999',
            'enabled' => 'boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
        ] + $translations->rules(Slider::TRANSLATION_FIELDS));
        if (
            !empty($data['starts_at']) &&
            !empty($data['ends_at']) &&
            $request->date('ends_at')->lte($request->date('starts_at'))
        ) {
            throw ValidationException::withMessages(['ends_at' => __('admin.slides.invalid_dates')]);
        }
        $asset = isset($data['media_asset_id']) ? MediaAsset::query()->find($data['media_asset_id']) : null;
        if ($request->hasFile('file')) {
            $asset = $library->store($request->file('file'), $request->user()->id, $data['alt']);
        }
        if (
            !$asset ||
            !in_array($asset->mime, Slider::IMAGE_TYPES, true) ||
            !Storage::disk('local')->exists($asset->path)
        ) {
            throw ValidationException::withMessages(['media_asset_id' => __('admin.slides.image_required')]);
        }
        unset($data['file']);
        $data['media_asset_id'] = $asset->id;
        $data['enabled'] = $request->boolean('enabled');
        $data['starts_at'] = $request->date('starts_at');
        $data['ends_at'] = $request->date('ends_at');
        $data['translations'] = $translations->normalize(
            $data['translations'] ?? [],
            $slide?->translations ?? [],
            Slider::TRANSLATION_FIELDS,
        );
        if ($slide) {
            $slide->update($data);
        } else {
            $slide = Slide::query()->create($data);
        }
        Audit::record('slides.saved', ['id' => $slide->id]);

        return redirect()->route('admin.slides.edit', $slide)->with('status', __('admin.slides.saved'));
    }

    public function toggle(Request $request, Slide $slide): RedirectResponse
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        $slide->update($data);
        Audit::record('slides.visibility_changed', ['id' => $slide->id, 'enabled' => $slide->enabled]);

        return back()->with('status', __('admin.slides.saved'));
    }

    public function move(Request $request, Slide $slide): RedirectResponse
    {
        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);
        DB::transaction(function () use ($slide, $data) {
            $slides = Slide::query()->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            $index = $slides->search(fn ($item) => $item->id === $slide->id);
            if ($index === false) {
                return;
            }
            $target = $index + ($data['direction'] === 'up' ? -1 : 1);
            if ($target < 0 || $target >= $slides->count()) {
                return;
            }
            $ordered = $slides->all();
            [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];
            foreach ($ordered as $position => $item) {
                $item->update(['position' => $position]);
            }
        }, 3);
        Audit::record('slides.reordered', ['id' => $slide->id]);

        return back()->with('status', __('admin.slides.reordered'));
    }

    public function destroy(Slide $slide): RedirectResponse
    {
        $id = $slide->id;
        $slide->delete();
        Audit::record('slides.deleted', ['id' => $id]);

        return redirect()->route('admin.slides.index')->with('status', __('admin.slides.deleted'));
    }
}
