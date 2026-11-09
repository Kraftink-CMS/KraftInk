<?php

namespace App\Http\Controllers\Admin\Appearance;

use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\HomeBlocks;
use App\Kraft\Html;
use App\Kraft\LocalePolicy;
use App\Kraft\Media\MediaLibrary;
use App\Kraft\Media\MediaSelection;
use App\Kraft\Settings;
use App\Kraft\TextTranslations;
use App\Models\Media\MediaAsset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class HomeConfigController extends Controller
{
    private const array HeadingFields = [
        'title' => ['max' => 160],
        'description' => ['type' => 'textarea', 'max' => 500, 'rows' => 2],
    ];

    public function index(Settings $settings): View
    {
        return view('admin.home.index', [
            'headingFields' => [
                'title' => self::HeadingFields['title'] + ['label' => __('common.labels.title')],
                'description' => self::HeadingFields['description'] + ['label' => __('common.labels.description')],
            ],
            'values' => [
                'title' => $settings->get('home.title', ''),
                'description' => $settings->get('home.description', ''),
                'heading_translations' => $settings->get('home.heading_translations', []),
                'messages' => $settings->get('home.messages', []),
                'alerts' => $settings->get('home.alerts', []),
                'alert_enabled' => $settings->get('home.alert_enabled', false),
                'background_media_id' => $settings->get('home.background_media_id'),
            ],
            'background' => MediaAsset::query()->find(
                old('background_media_id', $settings->get('home.background_media_id')),
            ),
        ]);
    }

    public function store(
        Request $request,
        Settings $settings,
        MediaLibrary $library,
        MediaSelection $selection,
        TextTranslations $translations,
    ): RedirectResponse {
        $policy = app(LocalePolicy::class);
        $locales = $policy->allowsChoice() ? array_keys(config('kraft.locales')) : [$policy->defaultLocale()];
        $rules = [
            'title' => ['nullable', 'string', 'max:'.self::HeadingFields['title']['max']],
            'description' => ['nullable', 'string', 'max:'.self::HeadingFields['description']['max']],
            'messages' => 'nullable|array:'.implode(',', $locales),
            'alerts' => 'nullable|array:'.implode(',', $locales),
            'alert_enabled' => 'required|boolean',
            'background_media_id' => 'nullable|integer|exists:media_assets,id',
            'clear_background' => 'nullable|boolean',
            'file' => $selection->rules('home-background', required: false),
            'image_alt' => 'nullable|string|max:255',
        ];
        $rules += $translations->rules(self::HeadingFields, 'heading_translations');
        if ($policy->allowsChoice()) {
            $rules['heading_translations.'.$policy->defaultLocale()] = ['missing'];
        }
        foreach ($locales as $locale) {
            $rules['messages.'.$locale] = 'nullable|string|max:20000';
            $rules['alerts.'.$locale] = 'nullable|string|max:5000';
        }
        $imageFields = ['alt' => ['max' => 255]];
        $rules += $translations->rules($imageFields, 'image_translations');
        $data = $request->validate($rules);
        $imageId = array_key_exists('background_media_id', $data)
            ? $data['background_media_id']
            : $settings->get('home.background_media_id');
        if ($request->hasFile('file')) {
            $image = $library->store($request->file('file'), $request->user()->id, $data['image_alt'] ?? '');
            $image->update([
                'translations' => $translations->normalize(
                    $data['image_translations'] ?? [],
                    $image->translations ?? [],
                    $imageFields,
                ),
            ]);
            $imageId = $image->id;
        }
        if ($request->boolean('clear_background')) {
            $imageId = null;
        }
        if ($imageId) {
            $image = MediaAsset::query()->find($imageId);
            if (
                !$image ||
                !in_array($image->mime, HomeBlocks::IMAGE_TYPES, true) ||
                !Storage::disk('local')->exists($image->path)
            ) {
                throw ValidationException::withMessages(['background_media_id' => __('admin.home.invalid_image')]);
            }
        }
        DB::transaction(function () use ($data, $settings, $imageId, $request, $locales, $translations) {
            $settings->set('home.title', $data['title'] ?? '');
            $settings->set('home.description', $data['description'] ?? '');
            if (array_key_exists('heading_translations', $data)) {
                $settings->set('home.heading_translations', $translations->normalize(
                    $data['heading_translations'] ?? [],
                    $settings->get('home.heading_translations', []),
                    self::HeadingFields,
                ));
            }
            foreach (['messages', 'alerts'] as $key) {
                $values = $settings->get('home.'.$key, []);
                foreach ($locales as $locale) {
                    if (array_key_exists($locale, $data[$key] ?? [])) {
                        $values[$locale] = Html::clean($data[$key][$locale] ?? '');
                    }
                }
                $settings->set('home.'.$key, $values);
            }
            $settings->set('home.alert_enabled', $request->boolean('alert_enabled'));
            $settings->set('home.background_media_id', $imageId);
            Audit::record('home.settings_saved');
        });

        return back()->with('status', __('admin.home.saved'));
    }
}
