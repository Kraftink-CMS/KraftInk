<?php

namespace App\Kraft\Extensions;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Illuminate\Translation\Translator;

class ThemeContext
{
    private ?array $base = null;

    private ?array $theme = null;

    public function target(Request $request): string
    {
        return $request->routeIs('admin.*') ||
            $request->is('admin', 'admin/*') ||
            in_array('permission:admin.access', $request->route()?->gatherMiddleware() ?? [], true)
            ? 'admin'
            : 'site';
    }

    public function current(): ?array
    {
        return $this->theme;
    }

    public function run(string $target, Closure $callback): mixed
    {
        $manager = app(ExtensionManager::class);
        $theme = $manager->activeTheme($target);
        if (!$theme && $this->base === null) {
            return app(ThemePreview::class)->protect($callback());
        }
        $finder = view()->getFinder();
        $translator = app('translator');
        $previousTheme = $this->theme;
        $outermost = $this->base === null;
        $this->base ??= ['finder' => $finder, 'translator' => $translator];
        $scopedFinder = clone $this->base['finder'];
        $scopedFinder->flush();
        $scopedTranslator = $theme
            ? new Translator(clone $this->base['translator']->getLoader(), $translator->getLocale())
            : clone $this->base['translator'];
        $scopedTranslator->setLocale($translator->getLocale());
        $scopedTranslator->setFallback($translator->getFallback());
        view()->setFinder($scopedFinder);
        Lang::swap($scopedTranslator);
        try {
            $this->theme = $theme;
            if (!$theme) {
                return app(ThemePreview::class)->protect($callback());
            }
            $views = ExtensionManager::viewsPath($theme);
            view()->replaceNamespace('theme', $views);
            $scopedTranslator->addNamespace('theme', $theme['path'].'/lang');
            if (is_dir($views)) {
                $scopedFinder->prependLocation($views);
            }
            if (is_dir($theme['path'].'/lang/overrides')) {
                $scopedTranslator->addPath($theme['path'].'/lang/overrides');
            }
            $errors = $views.'/errors';
            view()->replaceNamespace('theme-errors', is_dir($errors) ? [$errors] : []);
            if (is_dir($notifications = $views.'/vendor/notifications')) {
                view()->prependNamespace('notifications', $notifications);
            }
            foreach ($manager->all() as $extension) {
                if (
                    $extension['type'] === 'plugin' &&
                    $extension['enabled'] &&
                    is_dir($views.'/plugins/'.$extension['id'])
                ) {
                    view()->prependNamespace($extension['id'], $views.'/plugins/'.$extension['id']);
                }
            }

            return app(ThemePreview::class)->protect($callback());
        } finally {
            view()->setFinder($finder);
            Lang::swap($translator);
            $this->theme = $previousTheme;
            if ($outermost) {
                $this->base = null;
            }
        }
    }
}
