<?php

namespace App\Kraft\Extensions;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Random\RandomException;

class ThemePreview
{
    private int $suppressed = 0;

    public function draft(array $theme): ?array
    {
        $request = request();
        $token = $request->query('theme_preview');
        if (
            $this->suppressed ||
            !$request->isMethod('GET') ||
            !$request->hasSession() ||
            !is_string($token) ||
            strlen($token) !== 64 ||
            !$request->user()?->hasPermission('settings.manage') ||
            !$request->user()?->hasPermission($theme['configuration']['permission'] ?? 'settings.manage')
        ) {
            return null;
        }
        $draft = $request->session()->get('theme.preview');
        if (
            !is_array($draft) ||
            ($draft['theme'] ?? null) !== $theme['id'] ||
            ($draft['user'] ?? null) !== $request->user()->id ||
            ($draft['expires'] ?? 0) < time() ||
            !is_string($draft['token'] ?? null) ||
            !hash_equals($draft['token'], $token)
        ) {
            return null;
        }

        return $draft;
    }

    /**
     * @throws RandomException
     */
    public function start(Request $request, array $theme, array $data): string
    {
        $token = bin2hex(random_bytes(32));
        $request->session()->put(
            'theme.preview',
            $data + [
                'theme' => $theme['id'],
                'user' => $request->user()->id,
                'token' => $token,
                'expires' => time() + 1800,
            ],
        );

        return $token;
    }

    public function clear(array $theme): void
    {
        if (request()->hasSession() && request()->session()->get('theme.preview.theme') === $theme['id']) {
            request()->session()->forget('theme.preview');
        }
    }

    public function without(Closure $callback): mixed
    {
        $this->suppressed++;
        try {
            return $callback();
        } finally {
            $this->suppressed--;
        }
    }

    public function protect(mixed $result): mixed
    {
        if ($result instanceof Response && request()->query->has('theme_preview')) {
            $result->headers->set('Cache-Control', 'private, no-store');
            $result->headers->set('Referrer-Policy', 'no-referrer');
            $result->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $result;
    }
}
