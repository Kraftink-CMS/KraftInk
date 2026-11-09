<?php

namespace App\Mail\Theme;

use App\Kraft\Extensions\ThemeContext;
use App\Kraft\Extensions\ExtensionManager;
use Illuminate\Mail\Markdown;

class ThemeMarkdown extends Markdown
{
    protected function componentPaths(): array
    {
        $paths = [];
        if ($theme = app(ThemeContext::class)->current()) {
            $mailViews = ExtensionManager::viewsPath($theme).'/vendor/mail';
            if (is_dir($mailViews)) {
                $paths[] = $mailViews;
            }
        }

        return array_unique(array_merge($paths, parent::componentPaths()));
    }
}
