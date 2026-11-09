<?php

namespace App\Mail\Theme;

use App\Kraft\Extensions\ThemePreview;
use App\Kraft\Extensions\ThemeContext;
use Illuminate\Mail\Mailer;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

class ThemeMailer extends Mailer
{
    public function render($view, array $data = [])
    {
        [$html] = $this->parseView($view);
        $rendered = parent::render($view, $data);

        return $html && str_contains($rendered, '<style') ? new CssToInlineStyles()->convert($rendered) : $rendered;
    }

    protected function addContent($message, $view, $plain, $raw, $data)
    {
        parent::addContent($message, $view, $plain, $raw, $data);
        $html = $message->getSymfonyMessage()->getHtmlBody();
        if ($html !== null && str_contains($html, '<style')) {
            $message->html(new CssToInlineStyles()->convert($html));
        }
    }

    protected function renderView($view, $data)
    {
        return app(ThemePreview::class)->without(
            fn () => app(ThemeContext::class)->run('site', fn () => parent::renderView($view, $data)),
        );
    }
}
