<?php

namespace App\Http\Controllers\Site;

use App\Kraft\System\TableQuery;
use App\Models\Setting;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Media\ImageUrl;
use App\Kraft\Settings;
use App\Kraft\Site\SeoConfiguration;
use App\Kraft\Site\SeoPages;
use App\Kraft\Site\SiteIdentity;
use App\Kraft\TextTranslations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SeoController extends Controller
{
    public function index(Request $request, Settings $settings): View
    {
        $targets = app(SeoPages::class)->all();
        $pages = collect($settings->get('seo.pages', []))->sortBy('path')->values();
        $editing = $request->query('edit');
        $selectedTarget = $request->query('target');
        $page = null;
        if ($editing !== null) {
            $page = $pages->firstWhere('id', $editing);
            if ($page === null) {
                abort(404);
            }
        } elseif ($selectedTarget !== null) {
            if (!(is_string($selectedTarget) && isset($targets[$selectedTarget]))) {
                abort(404);
            }
            $page = $pages->firstWhere('target', $selectedTarget);
        }
        $activeSection =
            $page || $selectedTarget !== null || $request->query('tab') === 'pages' || old('_seo_section') === 'pages'
                ? 'pages'
                : 'general';
        if ($request->query('tab') === 'redirects') {
            $activeSection = 'redirects';
        }
        if (session('errors')?->any()) {
            if (in_array(old('_seo_section'), ['general', 'pages'], true)) {
                $activeSection = old('_seo_section');
            } elseif (str_starts_with((string) old('_modal'), 'redirect-')) {
                $activeSection = 'redirects';
            }
        }
        $identity = app(SiteIdentity::class);
        $previewVariables = [
            'title' => __('seo.preview_title'),
            'description' => __('seo.preview_description'),
            'website_name' => $identity->text('name'),
            'site_description' => $identity->text('description'),
            'url' => url('/example'),
            'path' => '/example',
            'locale' => app()->getLocale(),
            'year' => now()->format('Y'),
            'page' => '1',
        ];
        $pageValues = $page ?? ['target' => $selectedTarget ?? ''];
        $selectedTarget =
            old('_seo_section') === 'pages' ? old('target', $pageValues['target'] ?? '') : $pageValues['target'] ?? '';
        $hasSelectedTarget = is_string($selectedTarget) && isset($targets[$selectedTarget]);
        $pagePreviewDefaults = [
            'title' => $hasSelectedTarget ? $targets[$selectedTarget]['defaults']['title'] ?? '{title}' : '{title}',
            'title_template' => $settings->get('seo.title_template') ?: SeoConfiguration::DEFAULT_TITLE,
            'description' => $settings->get('seo.description_template') ?: SeoConfiguration::DEFAULT_DESCRIPTION,
            'keywords' => $settings->get('seo.keywords', ''),
        ];
        $commonVariables = collect(SeoConfiguration::VARIABLES)
            ->mapWithKeys(fn ($variable) => [$variable => __('seo.variables.'.$variable)])
            ->all();
        $pageVariableGroups = ['common' => $commonVariables];
        foreach ($targets as $key => $target) {
            if ($target['variables'] ?? []) {
                $pageVariableGroups[$key] = $target['variables'];
            }
        }

        return view('admin.seo.index', [
            'seoFields' => $this->fields(),
            'seoTargets' => $targets,
            'previewVariables' => $previewVariables,
            'previewTitle' => strtr(SeoConfiguration::DEFAULT_TITLE, [
                '{title}' => $previewVariables['title'],
                '{website_name}' => $previewVariables['website_name'],
            ]),
            'variableDialogs' => [
                'general' => ['id' => 'seo-global-variables', 'groups' => ['common' => $commonVariables]],
                'pages' => ['id' => 'seo-page-variables', 'groups' => $pageVariableGroups],
            ],
            'pageFields' => $this->fields(true),
            'pagePreviewDefaults' => $pagePreviewDefaults,
            'selectedTarget' => $selectedTarget,
            'hasSelectedTarget' => $hasSelectedTarget,
            'page' => $page,
            'pageValues' => $pageValues,
            'activeSection' => $activeSection,
            'redirectData' => $activeSection === 'redirects' ? app(RedirectController::class)->data($request) : null,
            'previewDefaults' => [
                'title' => SeoConfiguration::DEFAULT_TITLE,
                'description' => SeoConfiguration::DEFAULT_DESCRIPTION,
                'keywords' => '',
            ],
            'values' => [
                'title' => $settings->get('seo.title_template', SeoConfiguration::DEFAULT_TITLE),
                'description' => $settings->get('seo.description_template', SeoConfiguration::DEFAULT_DESCRIPTION),
                'keywords' => $settings->get('seo.keywords', ''),
                'robots' => $settings->get('seo.robots', 'index,follow'),
                'translations' => $settings->get('seo.translations', []),
                'sharing_image' => $settings->get('seo.image', ''),
            ],
            'pages' => TableQuery::paginate(
                $pages,
                ['path', 'title', 'robots'],
                pageName: 'seo_pages_page',
                search: ['path', 'title'],
            )
                ->appends(['tab' => 'pages'])
                ->fragment('pages'),
        ]);
    }

    public function update(Request $request, Settings $settings, TextTranslations $translations): RedirectResponse
    {
        $rules = SeoConfiguration::rules();
        $rules['sharing_image'] = ['nullable', 'string', 'max:500', new ImageUrl()];
        $data = $request->validate($rules);
        DB::transaction(function () use ($data, $settings, $translations): void {
            foreach (
                ['title' => 'title_template', 'description' => 'description_template', 'keywords' => 'keywords'] as $field => $key
            ) {
                $settings->set('seo.'.$key, trim($data[$field] ?? ''));
            }
            $settings->set('seo.robots', $data['robots'] ?? '' ?: 'index,follow');
            if (array_key_exists('sharing_image', $data)) {
                $settings->set('seo.image', trim($data['sharing_image'] ?? ''));
            }
            $settings->set(
                'seo.translations',
                $translations->normalize(
                    $data['translations'] ?? [],
                    $settings->get('seo.translations', []),
                    SeoConfiguration::fields(),
                ),
            );
            Audit::record('seo.settings_saved');
        });

        return redirect()->route('admin.seo.index')->with('status', __('seo.saved'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('admin.seo.index', ['tab' => 'pages']);
    }

    public function edit(string $rule, Settings $settings): RedirectResponse
    {
        $page = collect($settings->get('seo.pages', []))->firstWhere('id', $rule);
        if ($page === null) {
            abort(404);
        }

        return redirect()->route('admin.seo.index', ['edit' => $page['id']]);
    }

    public function store(
        Request $request,
        Settings $settings,
        TextTranslations $translations,
        ?string $rule = null,
    ): RedirectResponse {
        $target = $request->input('target');
        $targets = app(SeoPages::class)->forTargets(is_string($target) ? [$target] : []);
        $selection = $request->validate([
            'target' => ['required', 'string', 'max:300', Rule::in(array_keys($targets))],
            'path' => ['prohibited'],
        ]);
        $data = $request->validate(SeoConfiguration::rules(true, array_keys($targets[$selection['target']]['variables'] ?? [])));
        $path = $targets[$data['target']]['path'];
        DB::transaction(function () use ($data, $path, $settings, $translations, $rule): void {
            Setting::query()->where('key', 'seo.pages')->lockForUpdate()->first();
            $settings->refresh();
            $pages = $settings->get('seo.pages', []);
            $existing = $rule ? collect($pages)->firstWhere('id', $rule) : null;
            if ($rule !== null && $existing === null) {
                abort(404);
            }
            if (
                collect($pages)->contains(
                    fn ($page) => ($page['target'] ?? null) === $data['target'] && $page['id'] !== $rule,
                )
            ) {
                throw ValidationException::withMessages(['target' => __('seo.duplicate_target')]);
            }
            $page = [
                'id' => $rule ?? (string) Str::uuid(),
                'target' => $data['target'],
                'path' => $path,
                'robots' => $data['robots'] ?? '',
            ];
            foreach (array_keys(SeoConfiguration::fields(true)) as $field) {
                $page[$field] = trim($data[$field] ?? '');
            }
            $page['translations'] = $translations->normalize(
                $data['translations'] ?? [],
                $existing['translations'] ?? [],
                SeoConfiguration::fields(true),
            );
            $pages = array_values(array_filter($pages, fn ($item) => $item['id'] !== $page['id']));
            $pages[] = $page;
            $settings->set('seo.pages', $pages);
            Audit::record('seo.page_saved', ['path' => $path]);
        });

        return redirect(route('admin.seo.index', ['target' => $data['target']]).'#pages')->with(
            'status',
            __('seo.page_saved'),
        );
    }

    public function destroy(string $rule, Settings $settings): RedirectResponse
    {
        DB::transaction(function () use ($rule, $settings): void {
            Setting::query()->where('key', 'seo.pages')->lockForUpdate()->first();
            $settings->refresh();
            $pages = $settings->get('seo.pages', []);
            $page = collect($pages)->firstWhere('id', $rule);
            if ($page === null) {
                abort(404);
            }
            $settings->set('seo.pages', array_values(array_filter($pages, fn ($item) => $item['id'] !== $rule)));
            Audit::record('seo.page_deleted', ['path' => $page['path']]);
        });

        return redirect()
            ->route('admin.seo.index', ['tab' => 'pages'])
            ->with('status', __('seo.page_deleted'));
    }

    private function fields(bool $page = false): array
    {
        $fields = SeoConfiguration::fields($page);
        foreach ($fields as $field => &$options) {
            $options['label'] = __('seo.fields.'.$field);
            $options['help'] = __('seo.'.$field.'_help');
        }
        if (!$page) {
            $fields['title']['label'] = __('seo.title_structure');
            $fields['title']['help'] = __('seo.title_template_help');
            $fields['description']['label'] = __('seo.description_structure');
            $fields['description']['help'] = __('seo.description_template_help');
        }

        return $fields;
    }
}
