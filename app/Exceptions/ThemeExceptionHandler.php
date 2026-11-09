<?php

namespace App\Exceptions;

use App\Kraft\Extensions\ThemeContext;
use App\Kraft\LocalePolicy;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use Symfony\Component\HttpFoundation\Response;

class ThemeExceptionHandler extends Handler
{
    public function render($request, Throwable $e)
    {
        if ($this->shouldReturnJson($request, $e)) {
            return parent::render($request, $e);
        }
        $context = app(ThemeContext::class);
        $locale = app()->getLocale();
        try {
            if (!$request->route()) {
                app()->setLocale(app(LocalePolicy::class)->resolve($request));
            }

            return $context->run($context->target($request), fn () => parent::render($request, $e));
        } catch (Throwable $error) {
            if (config('app.debug')) {
                throw $error;
            }
            $this->report($error);

            return parent::render($request, $e);
        } finally {
            app()->setLocale($locale);
        }
    }

    protected function renderHttpException(HttpExceptionInterface $e): Response
    {
        $this->registerErrorViewPaths();
        if (app(ThemeContext::class)->current()) {
            foreach ([(string) $e->getStatusCode(), substr((string) $e->getStatusCode(), 0, 1).'xx'] as $code) {
                $view = 'theme-errors::'.$code;
                if (!view()->exists($view)) {
                    continue;
                }
                try {
                    return response()->view(
                        $view,
                        [
                            'errors' => new ViewErrorBag(),
                            'exception' => $e,
                        ],
                        $e->getStatusCode(),
                        $e->getHeaders(),
                    );
                } catch (Throwable $error) {
                    if (config('app.debug')) {
                        throw $error;
                    }
                    $this->report($error);
                    break;
                }
            }
        }

        return parent::renderHttpException($e);
    }
}
