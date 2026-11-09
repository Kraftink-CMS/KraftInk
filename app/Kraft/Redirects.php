<?php

namespace App\Kraft;

use App\Models\Site\SiteRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Redirects
{
    public function source(string $address): ?string
    {
        $address = trim($address);
        if (
            $address === '' ||
            strlen($address) > 4096 ||
            str_contains($address, '\\') ||
            preg_match('/[\x00-\x1f\x7f]/', $address) ||
            str_starts_with($address, '//')
        ) {
            return null;
        }
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $address)) {
            $parts = parse_url($address);
            if (
                $parts === false ||
                !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) ||
                empty($parts['host'])
            ) {
                return null;
            }
        } else {
            $parts = parse_url('/'.ltrim($address, '/'));
        }

        return $parts === false ? null : $this->path($parts['path'] ?? '/');
    }

    public function path(string $path): ?string
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $decoded = rawurldecode($path);
            if ($decoded === $path) {
                break;
            }
            $path = $decoded;
        }
        if (
            $path === '/' ||
            strlen($path) > 500 ||
            !preg_match('~^/(?!/)[^?#%\s\\\\\x00-\x1f\x7f]*$~u', $path) ||
            str_contains($path, '//')
        ) {
            return null;
        }
        if (array_any(explode('/', $path), fn ($part) => $part === '.' || $part === '..')) {
            return null;
        }
        if (
            preg_match(
                '~^/(?:admin|api|account|install|login|logout|register|oauth|reset-password|forgot-password|two-factor-challenge|media|extensions|build|vendor|up|language|notifications|verify-email|verify-game|email)(?:/|$)~i',
                $path,
            )
        ) {
            return null;
        }

        return $path;
    }

    public function validDestination(string $url): bool
    {
        if (strlen($url) > 1000 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (
            !str_starts_with($url, '/') &&
            (!filter_var($url, FILTER_VALIDATE_URL) ||
                !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true))
        ) {
            return false;
        }
        if (str_starts_with($url, '//')) {
            return false;
        }
        parse_str($parts['query'] ?? '', $query);

        return !$this->containsSecretKey($query);
    }

    public function localDestination(string $url): ?string
    {
        $parts = parse_url($url);
        if (
            $parts === false ||
            (isset($parts['host']) &&
                strcasecmp($parts['host'], parse_url(config('app.url'), PHP_URL_HOST) ?? '') !== 0)
        ) {
            return null;
        }

        return $this->path($parts['path'] ?? '/');
    }

    public function assertNoLoop(string $source, string $destination, ?int $ignoreId = null): void
    {
        $rules = SiteRedirect::query()
            ->where('enabled', true)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->lockForUpdate()
            ->pluck('destination', 'source')
            ->all();
        $rules[$source] = $destination;
        $visited = [];
        $cursor = $source;
        while (isset($rules[$cursor])) {
            if (isset($visited[$cursor])) {
                throw ValidationException::withMessages(['destination' => __('admin.redirects.redirect_loop')]);
            }
            $visited[$cursor] = true;
            $cursor = $this->localDestination($rules[$cursor]);
            if ($cursor === null) {
                return;
            }
        }
    }

    public function preserve(string $source, string $destination): bool
    {
        $source = $this->path($source);
        if (
            $source === null ||
            !$this->validDestination($destination) ||
            $source === $this->localDestination($destination)
        ) {
            return false;
        }
        try {
            return DB::transaction(function () use ($source, $destination) {
                $canonical = $this->localDestination($destination);
                SiteRedirect::query()->where('automatic', true)->where('source', $canonical)->delete();
                if (SiteRedirect::query()->where('source', $source)->lockForUpdate()->exists()) {
                    return false;
                }
                SiteRedirect::query()
                    ->where('automatic', true)
                    ->where('destination', $source)
                    ->update(['destination' => $destination]);
                $this->assertNoLoop($source, $destination);
                SiteRedirect::query()->create([
                    'source' => $source,
                    'destination' => $destination,
                    'status' => 301,
                    'enabled' => true,
                    'automatic' => true,
                ]);

                return true;
            }, 3);
        } catch (ValidationException) {
            return false;
        }
    }

    public function resolve(Request $request): ?RedirectResponse
    {
        if (!$request->isMethod('get') && !$request->isMethod('head')) {
            return null;
        }
        $path = $this->path($request->getPathInfo());
        if ($path === null) {
            return null;
        }
        $rule = SiteRedirect::query()->where('source', $path)->where('enabled', true)->first();
        if (!$rule || !in_array($rule->status, [301, 302], true) || !$this->validDestination($rule->destination)) {
            return null;
        }
        try {
            $this->assertNoLoop($rule->source, $rule->destination, $rule->id);
        } catch (ValidationException) {
            return null;
        }

        return redirect()->to($rule->destination, $rule->status)->header('Cache-Control', 'no-store');
    }

    private function containsSecretKey(array $values): bool
    {
        return array_any(
            $values,
            fn ($value, $key) => preg_match(
                '/token|password|secret|signature|credential|authorization|api_key|session|^code$/i',
                (string) $key,
            ) ||
                (is_array($value) && $this->containsSecretKey($value)),
        );
    }
}
