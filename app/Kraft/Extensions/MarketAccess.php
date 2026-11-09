<?php

namespace App\Kraft\Extensions;

use App\Kraft\Secrets;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class MarketAccess
{
    public function __construct(private Secrets $secrets)
    {
    }

    public function hasKey(string $sourceId): bool
    {
        return $this->key($sourceId) !== '';
    }

    public function headers(string $url, ?string $trustedSourceId = null): array
    {
        $catalogue = app(Market::class)->catalogues()[$trustedSourceId] ?? null;
        if (!$catalogue || !$this->sameOrigin($url, $catalogue['url'])) {
            return [];
        }
        $headers = [
            'KraftInk-Market-Protocol' => '1',
            'KraftInk-Version' => (string) config('manifest.version'),
            'KraftInk-Site-Url' => $this->siteUrl(),
            'Accept-Language' => app()->getLocale(),
        ];
        $key = $catalogue['supports_site_key'] ? $this->key($trustedSourceId) : '';
        if ($key !== '') {
            $headers['Authorization'] = 'Bearer '.$key;
        }

        return $headers;
    }

    public function cacheIdentity(string $sourceId): string
    {
        return hash('sha256', json_encode([
            $sourceId,
            app(Market::class)->catalogues()[$sourceId] ?? null,
            hash('sha256', $this->key($sourceId)),
            app()->getLocale(),
            config('manifest.version'),
            $this->siteUrl(),
        ], JSON_THROW_ON_ERROR));
    }

    public function save(string $sourceId, ?string $key, bool $remove = false): void
    {
        $catalogue = app(Market::class)->catalogues()[$sourceId] ?? null;
        if (!$catalogue || $this->secretName($sourceId, $catalogue['url']) === null) {
            throw ValidationException::withMessages(['source' => __('market.settings.source_invalid')]);
        }
        if (!$remove && !empty($key) && !$catalogue['supports_site_key']) {
            throw ValidationException::withMessages(['source' => __('market.settings.source_public')]);
        }
        if ($remove && $key !== null && $key !== '') {
            throw ValidationException::withMessages(['site_key' => __('market.settings.key_conflict')]);
        }
        if ($key !== null && $key !== '' && !preg_match('/^[A-Za-z0-9._~-]{16,512}$/D', $key)) {
            throw ValidationException::withMessages(['site_key' => __('market.settings.key_invalid')]);
        }
        if (!$remove && ($key === null || $key === '')) {
            return;
        }
        app(Market::class)->forget($sourceId);
        $this->secrets->set($this->secretName($sourceId, $catalogue['url']), $remove ? '' : $key);
        app(Market::class)->forget($sourceId);
        Cache::forever('kraft.market.generation', bin2hex(random_bytes(12)));
    }

    private function key(string $sourceId): string
    {
        $catalogue = app(Market::class)->catalogues()[$sourceId] ?? null;
        $secret = $catalogue ? $this->secretName($sourceId, $catalogue['url']) : null;

        return $catalogue && $catalogue['supports_site_key'] && $secret !== null ? $this->secrets->get($secret) : '';
    }

    private function secretName(string $sourceId, string $endpoint): ?string
    {
        if (!$this->sameOrigin($endpoint, $endpoint)) {
            return null;
        }
        $origin = 'https://'.strtolower(parse_url($endpoint, PHP_URL_HOST));

        return 'market.'.$sourceId.'.'.hash('sha256', $origin).'.site_key';
    }

    private function siteUrl(): string
    {
        $url = rtrim((string) config('app.url'), '/');
        $parts = parse_url($url);

        return $parts &&
            in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) &&
            !empty($parts['host']) &&
            !isset($parts['user']) &&
            !isset($parts['pass']) &&
            !isset($parts['query']) &&
            !isset($parts['fragment']) &&
            !preg_match('/[\x00-\x20\x7f]/', $url)
                ? $url
                : '';
    }

    private function sameOrigin(string $url, string $source): bool
    {
        $target = parse_url($url);
        $origin = parse_url($source);

        return $target &&
            $origin &&
            filter_var($url, FILTER_VALIDATE_URL) &&
            filter_var($source, FILTER_VALIDATE_URL) &&
            !preg_match('/[\x00-\x20\x7f\\\\]/', $url.$source) &&
            strtolower($target['scheme'] ?? '') === 'https' &&
            strtolower($origin['scheme'] ?? '') === 'https' &&
            !empty($target['host']) &&
            !empty($origin['host']) &&
            strtolower($target['host'] ?? '') === strtolower($origin['host'] ?? '') &&
            !isset($target['user']) &&
            !isset($target['pass']) &&
            !isset($origin['user']) &&
            !isset($origin['pass']) &&
            !isset($target['port']) &&
            !isset($origin['port']);
    }
}
