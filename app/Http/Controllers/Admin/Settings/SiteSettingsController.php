<?php

namespace App\Http\Controllers\Admin\Settings;

use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller;
use App\Kraft\Appearance;
use App\Kraft\Audit;
use App\Kraft\Auth\Passkeys;
use App\Kraft\Extensions\ExtensionManager;
use App\Kraft\Favicon;
use App\Kraft\Media\MediaLibrary;
use App\Kraft\Media\MediaSelection;
use App\Kraft\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use App\Kraft\LocalePolicy;
use App\Kraft\Media\ImageUrl;
use App\Kraft\SiteDates;
use App\Kraft\Site\SeoConfiguration;
use App\Kraft\Site\SiteIdentity;
use App\Kraft\System\SensitiveAction;
use App\Kraft\TextTranslations;

class SiteSettingsController extends Controller
{
    public function index(Settings $settings, Favicon $favicon): View
    {
        $appearancePolicy = app(Appearance::class)->policy(app(ExtensionManager::class)->activeTheme());

        return view('admin.settings.site.index', [
            'settings' => $settings->all(),
            'selectedFavicon' => $favicon->current(),
            'selectedLogo' => app(SiteIdentity::class)->logo(),
            'appearancePolicy' => $appearancePolicy,
            'appearanceModes' => array_values(array_intersect($appearancePolicy['modes'], ['light', 'dark'])),
        ]);
    }

    public function update(
        Request $request,
        Settings $settings,
        ExtensionManager $extensions,
        MediaSelection $media,
    ): RedirectResponse {
        $identity = app(SiteIdentity::class);
        try {
            $request->validate(['site_url' => 'sometimes|required|string|max:4096']);

            if ($request->filled('site_url')) {
                $request->merge(['site_url' => $identity->normalizeUrl($request->string('site_url')->toString())]);
            }
        } catch (ValidationException $exception) {
            if (is_string($request->input('site_url')) && str_contains($request->input('site_url'), '@')) {
                $request->request->remove('site_url');
            }
            throw $exception;
        }
        $validator = Validator::make(
            $request->all(),
            [
                'site_url' => 'sometimes|required|string|max:2048',
                'copyright' => 'sometimes|nullable|string|max:300',
                'logo_id' => 'sometimes|nullable|integer|min:1',
                'logo_upload' => $media->rules('site-logo', required: false),
            ],
            ['logo_upload.*' => __('admin.settings.identity.logo_invalid')],
        );
        $identityData = $validator->validate();
        $urlChanged =
            isset($identityData['site_url']) &&
            $identityData['site_url'] !== $identity->normalizeUrl(config('app.url'));
        if ($urlChanged) {
            app(SensitiveAction::class)->confirm($request);
            app(Passkeys::class)->ensureAlternateAccessForSiteUrl($identityData['site_url']);
        }
        $logoAsset =
            !$request->hasFile('logo_upload') && array_key_exists('logo_id', $identityData)
                ? $identity->validateLogo($identityData['logo_id'] ?? null)
                : null;
        $appearanceModes = array_values(
            array_intersect(app(Appearance::class)->modes($extensions->activeTheme()), ['light', 'dark']),
        );
        $validator = Validator::make(
            $request->all(),
            [
                'site_name' => 'required|string|max:120',
                'site_description' => 'nullable|string|max:500',
                'seo_title' => ['sometimes', 'nullable', 'string', 'max:120', SeoConfiguration::templateRule()],
                'seo_description' => ['sometimes', 'nullable', 'string', 'max:320', SeoConfiguration::templateRule()],
                'seo_image' => ['sometimes', 'nullable', 'string', 'max:500', new ImageUrl()],
                'theme' => 'nullable|string',
                'contact_email' => 'nullable|email|max:255',
                'locale' => ['sometimes', 'required', Rule::in(array_keys(config('kraft.locales')))],
                'locale_user_choice' => 'sometimes|boolean',
                'site_appearance' => [
                    'sometimes',
                    'required',
                    'string',
                    Rule::in(count($appearanceModes) > 1 ? ['user', ...$appearanceModes] : $appearanceModes),
                ],
            ],
            ['site_appearance.in' => __('admin.settings.appearance.unsupported_mode')],
        );
        $data = $validator->validate();
        $translate =
            app(LocalePolicy::class)->allowsChoice() &&
            (!$request->has('locale_user_choice') || $request->boolean('locale_user_choice'));
        $textTranslations = app(TextTranslations::class);
        $translationData = $request->validate(
            $translate
                ? $textTranslations->rules($identity::textFields(), 'site_translations')
                : ['site_translations' => ['exclude']],
        );
        $validator = Validator::make(
            $request->all(),
            [
                'favicon_id' => 'sometimes|nullable|integer|min:1',
                'favicon_upload' => $media->rules('site-favicon', required: false),
            ],
            ['favicon_upload.*' => __('admin.settings.identity.favicon.invalid')],
        );
        $faviconData = $validator->validate();
        $timezoneData = $request->validate([
            'timezone' => ['sometimes', 'required', Rule::in(SiteDates::zones())],
        ]);
        $faviconAsset =
            !$request->hasFile('favicon_upload') && array_key_exists('favicon_id', $faviconData)
                ? app(Favicon::class)->validateSelection($faviconData['favicon_id'] ?? null)
                : null;
        $previousTheme = $settings->get('theme');
        $selectedTheme = array_key_exists('theme', $data) ? ($data['theme'] ?: null) : $previousTheme;
        $extensions->assertThemeSelection($selectedTheme);
        if ($request->hasFile('logo_upload')) {
            $logoAsset = app(MediaLibrary::class)->store($request->file('logo_upload'), $request->user()->id);
        }
        if ($request->hasFile('favicon_upload')) {
            $faviconAsset = app(MediaLibrary::class)->store($request->file('favicon_upload'), $request->user()->id);
        }
        foreach (
            [
                'site_name' => 'site.name',
                'site_description' => 'site.description',
                'contact_email' => 'contact.email',
            ] as $field => $key
        ) {
            $settings->set($key, $data[$field] ?? '');
        }
        foreach (
            ['seo_title' => 'seo.title', 'seo_description' => 'seo.description', 'seo_image' => 'seo.image'] as $field => $key
        ) {
            if (array_key_exists($field, $data)) {
                $settings->set($key, $data[$field] ?? '');
            }
        }
        if ($translate && array_key_exists('site_translations', $translationData)) {
            $settings->set(
                'site.translations',
                $textTranslations->normalize(
                    $translationData['site_translations'] ?? [],
                    $settings->get('site.translations', []),
                    $identity::textFields(),
                ),
            );
        }
        if (array_key_exists('logo_id', $identityData) || $request->hasFile('logo_upload')) {
            $settings->set('site.logo_id', $logoAsset?->id);
        }
        if (array_key_exists('copyright', $identityData)) {
            $settings->set('site.copyright', $identityData['copyright'] ?? '');
        }
        if (array_key_exists('favicon_id', $faviconData) || $request->hasFile('favicon_upload')) {
            $settings->set('site.favicon_id', $faviconAsset?->id);
        }
        if (isset($data['locale'])) {
            $settings->set('site.locale', $data['locale']);
        }
        if (array_key_exists('locale_user_choice', $data)) {
            $settings->set('locale.user_choice', $request->boolean('locale_user_choice'));
        }
        if (array_key_exists('site_appearance', $data)) {
            $settings->set('site.appearance', $data['site_appearance']);
        }
        if (array_key_exists('timezone', $timezoneData)) {
            $settings->set('site.timezone', $timezoneData['timezone']);
        }
        if ($previousTheme !== $selectedTheme) {
            $extensions->selectTheme($selectedTheme);
        }
        if (isset($identityData['site_url'])) {
            $settings->set('site.url', $identityData['site_url']);
            $identity->apply();
        }
        Audit::record('settings.saved');

        return back()->with('status', __('admin.messages.settings_saved'));
    }
}
