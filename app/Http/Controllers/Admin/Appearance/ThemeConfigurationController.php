<?php

namespace App\Http\Controllers\Admin\Appearance;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Extensions\ExtensionManager;
use App\Kraft\Extensions\ThemeConfiguration;
use App\Kraft\Extensions\ThemePreview;
use App\Kraft\Extensions\ThemeTemplates;
use App\Kraft\NavigationTargets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Random\RandomException;

class ThemeConfigurationController extends Controller
{
    public function index(string $theme, ThemeConfiguration $configuration, ThemeTemplates $templates): View
    {
        $extension = $this->theme($theme);

        return view('admin.themes.configuration', [
            'theme' => $extension,
            'sections' => $configuration->sections($extension),
            'values' => $configuration->values($extension),
            'templateFields' => $templates->fields($extension),
            'templateSelections' => $templates->selections($extension),
            'contentField' => $templates->contentField($extension),
            'contentSelections' => $templates->contentSelections($extension),
            'configuration' => $configuration,
            'canPreview' => (app(ExtensionManager::class)->activeTheme($extension['target'])['id'] ?? null) === $theme,
        ]);
    }

    public function store(
        Request $request,
        string $theme,
        ThemeConfiguration $configuration,
        ThemeTemplates $templates,
    ): RedirectResponse {
        $extension = $this->theme($theme);
        ['values' => $values, 'templates' => $selections, 'content_templates' => $content] = $this->validated(
            $request,
            $extension,
            $configuration,
            $templates,
        );
        DB::transaction(function () use ($configuration, $templates, $extension, $values, $selections, $content): void {
            $configuration->save($extension, $values);
            $templates->save($extension, $selections);
            if ($content !== null) {
                $templates->saveContent($extension, $content);
            }
            Audit::record('theme.configured', ['id' => $extension['id']]);
        });
        app(ThemePreview::class)->clear($extension);

        return to_route('admin.themes.configuration.index', ['theme' => $theme])->with('status', __('themes.saved'));
    }

    /**
     * @throws RandomException
     */
    public function preview(
        Request $request,
        string $theme,
        ThemeConfiguration $configuration,
        ThemeTemplates $templates,
    ): RedirectResponse {
        $extension = $this->theme($theme);
        if ((app(ExtensionManager::class)->activeTheme($extension['target'])['id'] ?? null) !== $theme) {
            throw new NotFoundHttpException();
        }
        $data = $this->validated($request, $extension, $configuration, $templates);
        if ($data['content_templates'] === null) {
            unset($data['content_templates']);
        }
        $input = $request->validate(['preview_path' => ['nullable', 'string', 'max:500']]);
        $path = $input['preview_path'] ?? ($extension['target'] === 'admin' ? '/admin' : '/');
        if (
            !(
                str_starts_with($path, '/') &&
                NavigationTargets::validUrl($path) &&
                !str_contains(rawurldecode($path), '..') &&
                !str_contains($path, '?') &&
                !str_contains($path, '#')
            )
        ) {
            throw new HttpException(422);
        }
        $decodedPath = rawurldecode($path);
        $target = str_starts_with($decodedPath, '/admin/') || $decodedPath === '/admin' ? 'admin' : 'site';
        if ($target !== $extension['target']) {
            throw new HttpException(422);
        }
        $token = app(ThemePreview::class)->start($request, $extension, $data);

        return redirect()->to(url($path).'?theme_preview='.$token);
    }

    private function validated(
        Request $request,
        array $theme,
        ThemeConfiguration $configuration,
        ThemeTemplates $templates,
    ): array {
        $input = $request->validate([
            'values' => ['nullable', 'array'],
            'templates' => ['nullable', 'array'],
            'content_templates' => ['nullable', 'array'],
        ]);
        $content = $templates->validateContent($theme, $input['content_templates'] ?? []);

        return [
            'values' => $configuration->validate($theme, $input['values'] ?? []),
            'templates' => $templates->validate($theme, $input['templates'] ?? []),
            'content_templates' => $content,
        ];
    }

    private function theme(string $id): array
    {
        $manager = app(ExtensionManager::class);
        $theme = $manager->all()[$id] ?? null;
        if (!($theme && $theme['type'] === 'theme' && $manager->configurationAvailable($theme))) {
            throw new NotFoundHttpException();
        }
        $permission = $theme['configuration']['permission'] ?? 'settings.manage';
        if (!request()->user()?->hasPermission($permission)) {
            throw new HttpException(403);
        }

        return $theme;
    }
}
