<?php

namespace App\Kraft\Extensions;

use App\Exceptions\MarketRequestFailed;
use App\Kraft\Updates\UpdateChannel;
use Composer\Semver\VersionParser;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Random\RandomException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

class Market
{
    private ?float $deadline = null;

    public function listingForNotifications(): array
    {
        $this->deadline = microtime(true) + 5;
        try {
            return $this->listing();
        } finally {
            $this->deadline = null;
        }
    }

    public function sources(): array
    {
        return array_map(fn ($catalogue) => $catalogue['url'], $this->catalogues());
    }

    public function catalogues(): array
    {
        $sources = [];
        foreach (config('market.sources', []) as $source) {
            if (
                !is_array($source) ||
                !is_string($source['id'] ?? null) ||
                !preg_match('/^[a-z][a-z0-9-]{0,49}$/D', $source['id']) ||
                isset($sources[$source['id']]) ||
                !is_string($source['url'] ?? null) ||
                trim($source['url']) === '' ||
                !is_bool($source['supports_site_key'] ?? null)
            ) {
                throw new RuntimeException(__('market.settings.source_invalid'));
            }
            $url = trim($source['url']);
            $id = $source['id'];
            $name = $source['name'] ?? null;
            $signingKey = $source['signing_key'] ?? null;
            if ($signingKey !== null && (!is_string($signingKey) || strlen($signingKey) > 128 || trim($signingKey) === '')) {
                throw new RuntimeException(__('market.settings.source_invalid'));
            }
            $sources[$id] = [
                'id' => $id,
                'url' => $url,
                'name' => is_string($name) && trim($name) !== '' ? trim($name) : (parse_url($url, PHP_URL_HOST) ?: $url),
                'supports_site_key' => $source['supports_site_key'],
                'revocations_url' => $this->signedFeedUrl($source['revocations_url'] ?? null, $url),
                'signing_key' => $signingKey,
            ];
        }

        return $sources;
    }

    private function signedFeedUrl(mixed $url, string $endpoint): ?string
    {
        if (!is_string($url) || trim($url) === '') {
            return null;
        }
        try {
            $parts = $this->validateUrl($url);
            $origin = $this->validateUrl($endpoint);
        } catch (Throwable) {
            return null;
        }

        return strtolower($parts['host']) === strtolower($origin['host']) ? $url : null;
    }

    /**
     * @throws RandomException
     */
    public function refresh(?string $source = null): array
    {
        $sources = $this->sources();
        if ($source !== null && !isset($sources[$source])) {
            throw new RuntimeException(__('system.extensions.market_source_required'));
        }
        foreach ($sources as $id => $url) {
            if ($source === null || $source === $id) {
                Cache::forget($this->cacheKey($id).'.fresh');
                Cache::forget($this->cacheKey($id).'.retry');
                Cache::forget($this->cacheKey($id).'.denied');
            }
        }

        $listing = $this->listing();
        Cache::forever('kraft.market.generation', bin2hex(random_bytes(12)));

        return $listing;
    }

    public function listing(bool $allowStale = true): array
    {
        $resources = [];
        $sources = [];
        foreach ($this->catalogues() as $id => $catalogue) {
            $url = $catalogue['url'];
            $listing = $this->readSource($id, $allowStale);
            $sources[] = [
                'id' => $id,
                'url' => $url,
                'name' => $catalogue['name'],
                'state' => $listing['state'],
                'fetched_at' => $listing['fetched_at'],
                'access' => $listing['access'] ?? null,
                'error' => $listing['error'] ?? null,
            ];
            foreach ($listing['resources'] as $resource) {
                $resources[] = array_merge($resource, [
                    '_source_id' => $id,
                    '_source_url' => $url,
                    '_source_state' => $listing['state'],
                    '_source_access' => $listing['access'] ?? null,
                ]);
            }
        }
        $states = array_column($sources, 'state');
        $state = !$sources
            ? 'unconfigured'
            : (in_array('stale', $states, true)
                ? 'stale'
                : (array_intersect(['unavailable', 'denied'], $states)
                    ? 'unavailable'
                    : 'fresh'));

        return [
            'resources' => $resources,
            'sources' => $sources,
            'state' => $state,
            'fetched_at' => $sources[0]['fetched_at'] ?? null,
        ];
    }

    private function readSource(string $sourceId, bool $allowStale): array
    {
        $key = $this->cacheKey($sourceId);
        if ($allowStale && ($denied = Cache::get($key.'.denied'))) {
            return $denied;
        }
        if ($allowStale && ($cached = Cache::get($key.'.fresh'))) {
            return $cached + ['state' => 'fresh'];
        }
        if ($allowStale && Cache::has($key.'.retry')) {
            $cached = Cache::get($key.'.stale');
            $error = Cache::get($key.'.retry');

            return $cached
                ? array_replace($cached, ['state' => 'stale', 'error' => is_array($error) ? $error : null])
                : [
                    'resources' => [],
                    'state' => 'unavailable',
                    'fetched_at' => null,
                    'error' => is_array($error) ? $error : null,
                ];
        }
        $lock = Cache::lock($key.'.lock', 30);
        if (!$lock->get()) {
            if (!$allowStale) {
                throw new RuntimeException(__('system.extensions.market_install_unavailable'));
            }
            $cached = Cache::get($key.'.stale');

            return $cached
                ? $cached + ['state' => 'stale']
                : ['resources' => [], 'state' => 'unavailable', 'fetched_at' => null];
        }
        try {
            if ($allowStale && ($cached = Cache::get($key.'.fresh'))) {
                return $cached + ['state' => 'fresh'];
            }
            $entry = $this->fetch($sourceId, Cache::get($key.'.stale'));
            Cache::put($key.'.fresh', $entry, (int) config('market.cache_seconds', 60));
            Cache::put($key.'.stale', $entry, (int) config('market.stale_seconds', 86400));
            Cache::forget($key.'.retry');
            Cache::forget($key.'.denied');

            return $entry + ['state' => 'fresh'];
        } catch (Throwable $error) {
            if ($error instanceof MarketRequestFailed && $error->status >= 400 && $error->status < 500) {
                $denied = $this->deny($sourceId, $error);
                if (!$allowStale) {
                    throw $error;
                }

                return $denied;
            }
            $failure =
                $error instanceof MarketRequestFailed
                    ? ['code' => $error->reasonCode, 'message' => $error->getMessage(), 'status' => $error->status]
                    : null;
            Cache::put($key.'.retry', $failure ?? true, 60);
            if (!$allowStale) {
                if ($error instanceof MarketRequestFailed) {
                    throw $error;
                }
                throw new RuntimeException(__('system.extensions.market_install_unavailable'), previous: $error);
            }
            if ($cached = Cache::get($key.'.stale')) {
                return array_replace($cached, ['state' => 'stale', 'error' => $failure]);
            }

            return [
                'resources' => [],
                'state' => 'unavailable',
                'fetched_at' => null,
                'error' => $failure,
            ];
        } finally {
            $lock->release();
        }
    }

    public function package(string $id, ?string $requestedVersion = null, ?string $source = null): array
    {
        $sources = $this->sources();
        if (!$source || !isset($sources[$source])) {
            throw new RuntimeException(__('system.extensions.market_source_required'));
        }
        $listing = $this->readSource($source, false);
        $resource = collect($listing['resources'])->firstWhere('id', $id);
        if (!$resource) {
            throw new RuntimeException(__('system.extensions.market_resource_missing'));
        }
        if (app(BuiltinExtensions::class)->contains($resource['slug'])) {
            throw new RuntimeException(__('system.extensions.builtin_managed'));
        }
        if (($resource['access']['allowed'] ?? true) === false) {
            throw new MarketRequestFailed(
                $resource['access']['message'] ?? __('market.errors.resource_denied'),
                403,
                $resource['access']['code'] ?? 'resource_denied',
            );
        }
        if ($resource['manual_only']) {
            throw new RuntimeException(__('system.extensions.market_manual_only'));
        }
        $versions = $resource['versions'];
        usort($versions, fn (array $left, array $right): int => version_compare($right['version'], $left['version']));
        $lastError = null;
        $validation = null;
        foreach ($versions as $version) {
            if ($requestedVersion && $version['version'] !== $requestedVersion) {
                continue;
            }
            if (!isset($version['download']) || !app(UpdateChannel::class)->allows(VersionParser::parseStability($version['version']))) {
                continue;
            }
            try {
                app(ExtensionManager::class)->assertMarketRequirements($resource, $version);
                app(MarketSecurity::class)->assertPackageAllowed($source, $resource, $version['version']);

                return [
                    'resource' => $resource,
                    'version' => $version,
                    'source' => ['id' => $source, 'url' => $sources[$source]],
                ];
            } catch (ValidationException $error) {
                $validation ??= $error;
                if ($requestedVersion) {
                    throw ValidationException::withMessages([
                        'extension' => array_values(
                            array_unique([
                                __('system.extensions.market_no_compatible_version'),
                                ...Arr::flatten($error->errors()),
                            ]),
                        ),
                    ]);
                }
            } catch (Throwable $error) {
                $lastError = $error;
            }
        }
        if ($validation) {
            throw ValidationException::withMessages([
                'extension' => array_values(
                    array_unique([
                        __('system.extensions.market_no_compatible_version'),
                        ...Arr::flatten($validation->errors()),
                    ]),
                ),
            ]);
        }
        throw new RuntimeException(__('system.extensions.market_no_compatible_version'), previous: $lastError);
    }

    public function localized(string|array $text): string
    {
        if (is_string($text)) {
            return $text;
        }
        $normalized = [];
        foreach ($text as $language => $value) {
            $normalized[strtolower(str_replace('_', '-', $language))] ??= $value;
        }
        $locale = strtolower(str_replace('_', '-', app()->getLocale()));
        $base = explode('-', $locale)[0];

        return $normalized[$locale] ?? ($normalized[$base] ?? ($normalized['en'] ?? reset($normalized)));
    }

    public function packageUrl(string $url, ?string $trustedSourceId = null): string
    {
        $parts = $this->validateUrl($url);
        $host = strtolower($parts['host']);
        $sources = $this->sources();
        if ($trustedSourceId !== null && !isset($sources[$trustedSourceId])) {
            throw new RuntimeException(__('system.extensions.download_untrusted'));
        }
        if (
            array_any(
                $trustedSourceId !== null ? [$sources[$trustedSourceId]] : $sources,
                fn ($endpoint) => $host === strtolower($this->validateUrl($endpoint)['host']),
            )
        ) {
            return $url;
        }
        $path = $parts['path'] ?? '';
        if (
            $host === 'github.com' &&
            preg_match('~^/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/releases/download/[^/]+/[^/]+\.zip$~D', $path)
        ) {
            return $url;
        }
        if (
            $host === 'github.com' &&
            preg_match(
                '~^/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)/archive/(refs/(?:tags|heads)/.+)\.zip$~D',
                $path,
                $matches,
            )
        ) {
            return 'https://codeload.github.com/'.$matches[1].'/'.$matches[2].'/zip/'.$matches[3];
        }
        if (
            $host === 'codeload.github.com' &&
            preg_match('~^/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/zip/refs/(?:tags|heads)/[^?]+$~D', $path)
        ) {
            return $url;
        }
        throw new RuntimeException(__('system.extensions.download_untrusted'));
    }

    public function allowedDownloadHost(string $host, ?string $trustedSourceId = null): bool
    {
        $sources = $this->sources();
        if ($trustedSourceId !== null && !isset($sources[$trustedSourceId])) {
            return false;
        }
        if (
            in_array(
                strtolower($host),
                [
                    'github.com',
                    'codeload.github.com',
                    'objects.githubusercontent.com',
                    'release-assets.githubusercontent.com',
                ],
                true,
            )
        ) {
            return true;
        }

        return array_any(
            $trustedSourceId !== null ? [$sources[$trustedSourceId]] : $sources,
            fn ($endpoint) => strtolower($host) === strtolower(parse_url($endpoint, PHP_URL_HOST)),
        );
    }

    public function repositoryUrl(string $repository): string
    {
        $parts = $this->validateUrl($repository);
        if (
            ($parts['host'] ?? '') !== 'github.com' ||
            !preg_match('~^/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/?$~D', $parts['path'] ?? '')
        ) {
            throw new RuntimeException(__('system.extensions.catalogue_invalid'));
        }

        return rtrim($repository, '/');
    }

    private function cacheKey(string $sourceId): string
    {
        return 'kraft.market.v'.
            config('market.schema_version', 1).
            '.source.'.
            app(MarketAccess::class)->cacheIdentity($sourceId);
    }

    public function forget(string $sourceId): void
    {
        $key = $this->cacheKey($sourceId);
        foreach (['fresh', 'stale', 'retry', 'denied'] as $suffix) {
            Cache::forget($key.'.'.$suffix);
        }
    }

    public function deny(string $sourceId, MarketRequestFailed $error): array
    {
        $key = $this->cacheKey($sourceId);
        foreach (['fresh', 'stale', 'retry'] as $suffix) {
            Cache::forget($key.'.'.$suffix);
        }
        $entry = [
            'resources' => [],
            'state' => 'denied',
            'fetched_at' => now()->toIso8601String(),
            'error' => ['code' => $error->reasonCode, 'message' => $error->getMessage(), 'status' => $error->status],
        ];
        Cache::put($key.'.denied', $entry, 60);
        Cache::forever('kraft.market.generation', bin2hex(random_bytes(12)));

        return $entry;
    }

    public function checkResponse(Response $response): void
    {
        if (!$response->failed()) {
            return;
        }
        $status = $response->status();
        $fallback = __(
            'market.errors.'.
                match ($status) {
                    401 => 'unauthorized',
                    403 => 'forbidden',
                    404 => 'not_found',
                    429 => 'rate_limited',
                    default => $status >= 500 ? 'unavailable' : 'request_failed',
                },
        );
        $reason = [];
        try {
            $body = $response->toPsrResponse()->getBody();
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $raw = $body->read(65537);
            if (strlen($raw) <= 65536) {
                $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
                if (is_array($payload) && is_array($payload['error'] ?? null)) {
                    $reason = $this->reason($payload['error']);
                }
            }
        } catch (Throwable) {
        }

        throw new MarketRequestFailed($reason['message'] ?? $fallback, $status, $reason['code'] ?? 'http_'.$status);
    }

    private function reason(array $value): array
    {
        $reason = [];
        if (array_key_exists('code', $value)) {
            if (!is_string($value['code']) || !preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $value['code'])) {
                throw new RuntimeException(__('system.extensions.catalogue_invalid'));
            }
            $reason['code'] = $value['code'];
        }
        if (
            in_array(
                $reason['code'] ?? null,
                [
                    'site_key_required',
                    'site_access_invalid',
                    'site_key_invalid',
                    'site_key_expired',
                    'site_key_revoked',
                    'ip_not_allowed',
                    'domain_not_allowed',
                    'purchase_required',
                    'resource_not_found',
                    'version_not_found',
                    'rate_limited',
                    'service_unavailable',
                    'resource_denied',
                ],
                true,
            )
        ) {
            $reason['message'] = __('market.reasons.'.$reason['code']);

            return $reason;
        }
        if (isset($reason['code'])) {
            $reason['message'] = __('market.errors.request_failed');
        }

        return $reason;
    }

    private function access(mixed $value, bool $catalogue = false): array
    {
        if (
            !is_array($value) ||
            ($catalogue
                ? !in_array($value['scope'] ?? null, ['public', 'private'], true)
                : !is_bool($value['allowed'] ?? null))
        ) {
            throw new RuntimeException(__('system.extensions.catalogue_invalid'));
        }

        return [$catalogue ? 'scope' : 'allowed' => $value[$catalogue ? 'scope' : 'allowed']] + $this->reason($value);
    }

    private function validateUrl(string $url): array
    {
        $parts = parse_url($url);
        if (
            preg_match('/[\x00-\x20\x7f"\'<>`\\\\]/', $url) ||
            !filter_var($url, FILTER_VALIDATE_URL) ||
            strtolower($parts['scheme'] ?? '') !== 'https' ||
            empty($parts['host']) ||
            isset($parts['user']) ||
            isset($parts['pass']) ||
            isset($parts['query']) ||
            isset($parts['fragment']) ||
            isset($parts['port'])
        ) {
            throw new RuntimeException(__('system.extensions.market_url_invalid'));
        }

        return $parts;
    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    private function fetch(string $sourceId, ?array $previous = null): array
    {
        $endpoint = $this->sources()[$sourceId] ?? throw new RuntimeException(__('market.settings.source_invalid'));
        $this->validateUrl($endpoint);
        $timeout = $this->deadline === null ? 15 : $this->deadline - microtime(true);
        if ($timeout <= 0) {
            throw new RuntimeException('Extension update check time budget exceeded.');
        }
        $headers = app(MarketAccess::class)->headers($endpoint, $sourceId);
        foreach (['etag' => 'If-None-Match', 'last_modified' => 'If-Modified-Since'] as $field => $header) {
            $value = $previous[$field] ?? null;
            if (is_string($value) && strlen($value) <= 1024 && !preg_match('/[\r\n]/', $value)) {
                $headers[$header] = $value;
            }
        }
        $response = Http::acceptJson()
            ->withHeaders($headers)
            ->timeout($timeout)
            ->connectTimeout(min(5, $timeout))
            ->withOptions([
                'allow_redirects' => false,
                'on_headers' => function ($response): void {
                    if ((int) $response->getHeaderLine('Content-Length') > 2097152) {
                        throw new MarketRequestFailed(
                            __('system.extensions.catalogue_invalid'),
                            422,
                            'invalid_catalogue',
                        );
                    }
                },
                'progress' => function ($total, $downloaded): void {
                    if ($this->deadline !== null && microtime(true) >= $this->deadline) {
                        throw new RuntimeException('Extension update check time budget exceeded.');
                    }
                    if ($downloaded > 2097152) {
                        throw new MarketRequestFailed(
                            __('system.extensions.catalogue_invalid'),
                            422,
                            'invalid_catalogue',
                        );
                    }
                },
            ])
            ->get($endpoint);
        $this->checkResponse($response);
        if ($response->status() === 304 && $previous !== null) {
            return array_replace($previous, ['fetched_at' => now()->toIso8601String()]);
        }
        if ($response->status() !== 200 || strlen($response->body()) > 2097152) {
            throw new MarketRequestFailed(__('system.extensions.catalogue_invalid'), 422, 'invalid_catalogue');
        }
        try {
            $feed = $response->json();
            $resources = $this->validate($feed, $sourceId);
            $access = array_key_exists('access', $feed) ? $this->access($feed['access'], true) : null;
            if (($access['scope'] ?? null) === 'private' && !isset($headers['Authorization'])) {
                throw new MarketRequestFailed(__('market.errors.unauthorized'), 401, 'site_key_required');
            }
            if (($access['scope'] ?? null) === 'public' || !isset($headers['Authorization'])) {
                foreach ($resources as &$resource) {
                    if (
                        $resource['commercial']['kind'] === 'paid' &&
                        !$resource['manual_only'] &&
                        ($resource['access']['allowed'] ?? false)
                    ) {
                        $resource['access'] = ['allowed' => false] + $this->reason(['code' => 'purchase_required']);
                        foreach ($resource['versions'] as &$version) {
                            unset($version['download']);
                        }
                        unset($version);
                    }
                }
                unset($resource);
            }
        } catch (MarketRequestFailed $error) {
            throw $error;
        } catch (Throwable) {
            throw new MarketRequestFailed(__('system.extensions.catalogue_invalid'), 422, 'invalid_catalogue');
        }

        return [
            'resources' => $resources,
            'access' => $access,
            'fetched_at' => now()->toIso8601String(),
            'etag' => $response->header('ETag'),
            'last_modified' => $response->header('Last-Modified'),
        ];
    }

    private function validate(mixed $feed, string $sourceId): array
    {
        if (
            !is_array($feed) ||
            ($feed['schema_version'] ?? null) !== (int) config('market.schema_version', 1) ||
            !is_array($feed['resources'] ?? null) ||
            count($feed['resources']) > 1000
        ) {
            throw new RuntimeException(__('system.extensions.catalogue_invalid'));
        }
        $resources = [];
        foreach ($feed['resources'] as $resource) {
            if (is_array($resource)) {
                $resource['manual_only'] ??= ($resource['commercial']['kind'] ?? null) === 'paid';
                if (array_key_exists('access', $resource)) {
                    $resource['access'] = $this->access($resource['access']);
                }
            }
            if (
                !is_array($resource) ||
                !preg_match('~^([a-z][a-z0-9-]{1,49})/([a-z][a-z0-9-]{1,49})$~D', $resource['id'] ?? '', $identity) ||
                isset($resources[$resource['id']]) ||
                ($resource['slug'] ?? null) !== $identity[2] ||
                !in_array($resource['type'] ?? null, ['plugin', 'theme', 'game'], true) ||
                ($resource['author']['id'] ?? null) !== $identity[1] ||
                !is_string($resource['author']['name'] ?? null) ||
                !in_array($resource['commercial']['kind'] ?? null, ['free', 'paid'], true) ||
                !is_bool($resource['manual_only'] ?? null)
            ) {
                throw new RuntimeException(__('system.extensions.catalogue_invalid'));
            }
            $this->translations($resource['name'] ?? null, 120);
            $this->translations($resource['description'] ?? null, 1000);
            if (array_key_exists('image', $resource)) {
                $this->translations($resource['image'], 2048);
                foreach (is_array($resource['image']) ? $resource['image'] : [$resource['image']] as $image) {
                    $this->validateUrl($image);
                }
            }
            if (array_key_exists('price', $resource['commercial'])) {
                $price = $resource['commercial']['price'];
                if (
                    !is_array($price) ||
                    !is_string($price['amount'] ?? null) ||
                    !preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/D', $price['amount']) ||
                    !is_string($price['currency'] ?? null) ||
                    !preg_match('/^[A-Z]{3}$/D', $price['currency'])
                ) {
                    throw new RuntimeException(__('system.extensions.catalogue_invalid'));
                }
            }
            if (mb_strlen($resource['author']['name']) > 120 || trim($resource['author']['name']) === '') {
                throw new RuntimeException(__('system.extensions.catalogue_invalid'));
            }
            if (isset($resource['repository'])) {
                $resource['repository'] = $this->repositoryUrl($resource['repository']);
            }
            foreach ([$resource['author']['url'] ?? null, $resource['commercial']['url'] ?? null] as $url) {
                if ($url !== null) {
                    $this->validateUrl($url);
                }
            }
            if (
                !is_array($resource['versions'] ?? null) ||
                count($resource['versions']) < 1 ||
                count($resource['versions']) > 100
            ) {
                throw new RuntimeException(__('system.extensions.catalogue_invalid'));
            }
            $versions = [];
            foreach ($resource['versions'] as $version) {
                if (!is_array($version) || !GameSupport::valid($version, $resource['type'])) {
                    throw new RuntimeException(__('system.extensions.catalogue_invalid'));
                }
                $normalized = new VersionParser()->normalize($version['version'] ?? '');
                if (isset($versions[$normalized])) {
                    throw new RuntimeException(__('system.extensions.catalogue_invalid'));
                }
                foreach (['cms', 'php'] as $key) {
                    if (!is_string($version['requirements'][$key] ?? null)) {
                        throw new RuntimeException(__('system.extensions.catalogue_invalid'));
                    }
                    new VersionParser()->parseConstraints($version['requirements'][$key]);
                }
                try {
                    app(DependencyConstraints::class)->map($version['requirements'], true);
                } catch (Throwable $error) {
                    throw new RuntimeException(__('system.extensions.catalogue_invalid'), previous: $error);
                }
                if (
                    (!$resource['manual_only'] && ($resource['access']['allowed'] ?? true)) ||
                    isset($version['download'])
                ) {
                    $this->packageUrl($version['download']['url'] ?? '', $sourceId);
                    if (!preg_match('/^[a-f0-9]{64}$/Di', $version['download']['sha256'] ?? '')) {
                        throw new RuntimeException(__('system.extensions.catalogue_invalid'));
                    }
                }
                $versions[$normalized] = $version;
            }
            $resources[$resource['id']] = $resource;
        }

        return array_values($resources);
    }

    private function translations(mixed $text, int $maximum): void
    {
        if (is_string($text)) {
            if (preg_match('//u', $text) !== 1 || mb_strlen($text) > $maximum || trim($text) === '') {
                throw new RuntimeException(__('system.extensions.catalogue_invalid'));
            }

            return;
        }
        if (!is_array($text) || $text === [] || count($text) > 100) {
            throw new RuntimeException(__('system.extensions.catalogue_invalid'));
        }
        foreach ($text as $locale => $value) {
            if (
                !is_string($locale) ||
                strlen($locale) > 35 ||
                !preg_match('/^[a-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/D', $locale) ||
                !is_string($value) ||
                preg_match('//u', $value) !== 1 ||
                mb_strlen($value) > $maximum ||
                trim($value) === ''
            ) {
                throw new RuntimeException(__('system.extensions.catalogue_invalid'));
            }
        }
    }
}
