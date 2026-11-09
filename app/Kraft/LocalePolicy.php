<?php

namespace App\Kraft;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Setup\Installation;
use App\Models\Accounts\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class LocalePolicy
{
    public const string COOKIE = 'kraft_locale';

    private readonly string $fallback;

    public function __construct(private readonly Settings $settings)
    {
        $this->fallback = (string) config('app.locale');
    }

    public function valid(mixed $locale): bool
    {
        return is_string($locale) && array_key_exists($locale, config('kraft.locales', []));
    }

    public function defaultLocale(): string
    {
        $locale = $this->settings->get('site.locale', $this->fallback);

        return $this->valid($locale)
            ? $locale
            : ($this->valid($this->fallback)
                ? $this->fallback
                : array_key_first(config('kraft.locales')));
    }

    public function allowsChoice(): bool
    {
        return (bool) $this->settings->get('locale.user_choice', false);
    }

    public function forUser(User $user): string
    {
        return $this->allowsChoice() && $this->valid($user->locale) ? $user->locale : $this->defaultLocale();
    }

    public function installationLocale(Request $request): string
    {
        $fallback = $this->valid($this->fallback) ? $this->fallback : array_key_first(config('kraft.locales'));
        $explicit = $request->isMethod('get') ? $request->query('locale') : $request->input('locale');
        if ($this->valid($explicit)) {
            if ($request->hasSession()) {
                $request->session()->put('installation.locale', $explicit);
            }

            return $explicit;
        }
        $saved = $request->hasSession() ? $request->session()->get('installation.locale') : null;
        if ($this->valid($saved)) {
            return $saved;
        }
        if ($explicit !== null) {
            return $fallback;
        }

        return $request->getPreferredLanguage(
            array_values(array_unique([$fallback, ...array_keys(config('kraft.locales'))])),
        ) ?? $fallback;
    }

    public function resolve(Request $request, ?User $user = null): string
    {
        if (!app(Installation::class)->installed() && $request->is('install', 'install/*')) {
            return $this->installationLocale($request);
        }
        $default = $this->defaultLocale();
        if (!$this->allowsChoice()) {
            return $default;
        }
        $user ??= $request->hasSession() ? $request->user() : null;
        if ($user && $this->valid($user->locale)) {
            return $user->locale;
        }
        $cookie = $request->cookie(self::COOKIE);
        if (!$user && $this->valid($cookie)) {
            return $cookie;
        }
        $session = $request->hasSession() ? $request->session()->get('locale') : null;

        return $this->valid($session) ? $session : $default;
    }

    public function choose(Request $request, string $locale): Cookie
    {
        if (!$this->allowsChoice()) {
            throw new HttpException(403);
        }
        if (!$this->valid($locale)) {
            throw new NotFoundHttpException();
        }
        if ($request->hasSession()) {
            $request->session()->put('locale', $locale);
        }
        if ($request->user() && $request->user()->locale !== $locale) {
            $request
                ->user()
                ->forceFill(['locale' => $locale])
                ->save();
        }

        return cookie()->make(
            self::COOKIE,
            $locale,
            525600,
            '/',
            config('session.domain'),
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_LAX,
        );
    }
}
