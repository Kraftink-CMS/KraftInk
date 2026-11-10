<?php

namespace App\Kraft\Updates;

use App\Kraft\SigningKeys;
use Composer\Semver\Semver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use SodiumException;
use JsonException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

class GitHubReleaseRepository
{
    private ?float $deadline = null;

    public function checkForNotifications(): array
    {
        $this->deadline = microtime(true) + 8;
        try {
            return $this->check();
        } finally {
            $this->deadline = null;
        }
    }

    public function repository(): string
    {
        return $this->normalizeRepository(config('manifest.update.repository'));
    }

    public function normalizeRepository(string $repository): string
    {
        $repository = trim($repository);
        if (str_starts_with($repository, 'https://github.com/')) {
            $repository = substr($repository, strlen('https://github.com/'));
        }
        $repository = rtrim($repository, '/');
        if (
            !preg_match('~^[A-Za-z0-9_-][A-Za-z0-9_.-]*/[A-Za-z0-9_-][A-Za-z0-9_.-]*$~D', $repository) ||
            str_ends_with($repository, '.git')
        ) {
            throw new RuntimeException('Invalid CMS update repository.');
        }

        return $repository;
    }

    public function snapshot(): array
    {
        return Cache::get($this->cacheKey(), ['state' => 'unchecked']);
    }

    public function check(bool $refresh = false): array
    {
        if (!$refresh && ($cached = Cache::get($this->cacheKey()))) {
            return $cached;
        }
        $previousDeadline = $this->deadline;
        $this->deadline ??= microtime(true) + 20;
        try {
            $verified = $this->checkChannels();
            if ($verified === [] && app(UpdateChannel::class)->current() === 'stable') {
                $latest = $this->latestStable();
                if ($latest === null) {
                    return $this->remember(['state' => 'unpublished', 'checked_at' => now()->toIso8601String()]);
                }
                $verified[$latest['tag_name']] = $this->release($latest);
            }

            $selected = $this->select($verified);
            if ($selected === null || $selected['state'] === 'incompatible' || isset($selected['blocked'])) {
                $selected = $this->checkHistory($verified, $selected);
            }

            return $this->remember($selected ?? ['state' => 'unpublished', 'checked_at' => now()->toIso8601String()]);
        } finally {
            $this->deadline = $previousDeadline;
        }
    }

    private function latestStable(): ?array
    {
        $response = $this->request('https://api.github.com/repos/'.$this->repository().'/releases/latest', true);
        if ($response->status() === 404) {
            return null;
        }
        $release = json_decode($this->bytes($response, 1048576), true, 32, JSON_THROW_ON_ERROR);
        if (
            !is_array($release) ||
            !is_string($release['tag_name'] ?? null) ||
            ReleaseVersion::channel(preg_replace('/^v/', '', $release['tag_name'])) !== 'stable'
        ) {
            throw new RuntimeException('Invalid stable CMS release.');
        }

        return $release;
    }

    private function checkChannels(): array
    {
        try {
            $repository = $this->repository();
            $response = $this->request(
                'https://raw.githubusercontent.com/'.$repository.'/release-channels/channels.json',
                true,
            );
            if ($response->status() === 404) {
                return [];
            }
            $index = json_decode($this->bytes($response, 4096), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($index) ||
                ($index['schema'] ?? null) !== 1 ||
                !is_array($index['channels'] ?? null) ||
                count($index['channels']) > 3
            ) {
                throw new RuntimeException('Invalid CMS release channel index.');
            }
            $channels = app(UpdateChannel::class);
            $tags = [];
            foreach ($index['channels'] as $channel => $tag) {
                if (
                    !in_array($channel, ['alpha', 'beta', 'stable'], true) ||
                    !is_string($tag) ||
                    !str_starts_with($tag, 'v') ||
                    ReleaseVersion::channel(substr($tag, 1)) !== $channel
                ) {
                    throw new RuntimeException('Invalid CMS release channel index.');
                }
                if ($channels->allows($channel)) {
                    $tags[] = $tag;
                }
            }
            if (!array_key_exists($channels->current(), $index['channels'])) {
                return [];
            }
            usort($tags, fn ($left, $right) => version_compare(substr($right, 1), substr($left, 1)));
            $verified = [];
            foreach ($tags as $tag) {
                $response = $this->request(
                    'https://api.github.com/repos/'.$repository.'/releases/tags/'.rawurlencode($tag),
                    true,
                );
                $release = json_decode($this->bytes($response, 1048576), true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($release) || ($release['tag_name'] ?? null) !== $tag) {
                    throw new RuntimeException('The CMS release channel points to another tag.');
                }
                $verified[$tag] = $this->release($release);
                if ($channels->current() !== 'alpha' && $verified[$tag]['state'] === 'available') {
                    break;
                }
            }

            return $verified;
        } catch (Throwable $exception) {
            report($exception);
        }

        return [];
    }

    private function checkHistory(array $verified, ?array $selected): ?array
    {
        $candidates = [];
        $failure = null;
        if ($verified === []) {
            try {
                if (($stable = $this->latestStable()) !== null) {
                    $candidates[$stable['tag_name']] = $stable;
                }
            } catch (Throwable $exception) {
                $failure = $exception;
                report($exception);
            }
        }
        $channels = app(UpdateChannel::class);
        for ($page = 1; $page <= 10; $page++) {
            $response = $this->request(
                'https://api.github.com/repos/'.$this->repository().'/releases?per_page=100&page='.$page,
                true,
            );
            if ($response->status() === 404) {
                break;
            }
            $releases = json_decode($this->bytes($response, 4194304), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($releases) || !array_is_list($releases)) {
                throw new RuntimeException('Invalid CMS release catalogue.');
            }
            foreach ($releases as $release) {
                if (
                    !is_array($release) ||
                    !is_string($release['tag_name'] ?? null) ||
                    !empty($release['draft']) ||
                    empty($release['published_at'])
                ) {
                    continue;
                }
                $version = preg_replace('/^v/', '', $release['tag_name'] ?? '');
                $channel = ReleaseVersion::channel($version);
                if (
                    $channel !== null &&
                    $channels->allows($channel) &&
                    ($release['prerelease'] ?? null) === ($channel !== 'stable')
                ) {
                    $candidates[$release['tag_name']] = $release;
                }
            }
            if (count($releases) < 100) {
                break;
            }
        }
        uksort(
            $candidates,
            fn ($left, $right) => version_compare(preg_replace('/^v/', '', $right), preg_replace('/^v/', '', $left)),
        );
        $alpha = $channels->current() === 'alpha';
        $installed = config('manifest.version');
        $bestRank = ($selected['state'] ?? null) === 'available' ? $this->rank($selected, $alpha) : null;
        foreach ($candidates as $tag => $release) {
            $version = preg_replace('/^v/', '', $tag);
            if (
                ($verified !== [] && version_compare($version, $installed, '<=')) ||
                ($bestRank !== null && version_compare($version, $bestRank, '<'))
            ) {
                break;
            }
            if (isset($verified[$tag])) {
                continue;
            }
            if (microtime(true) >= $this->deadline) {
                throw new RuntimeException('CMS release check time budget exceeded.');
            }
            try {
                $result = $this->release($release);
                $verified[$tag] = $result;
                if ($result['state'] === 'available') {
                    $rank = $this->rank($result, $alpha);
                    if ($bestRank === null || version_compare($rank, $bestRank, '>')) {
                        $bestRank = $rank;
                    }
                }
            } catch (Throwable $exception) {
                if (microtime(true) >= $this->deadline) {
                    throw $exception;
                }
                $failure ??= $exception;
                report($exception);
            }
        }
        if ($verified === [] && $failure !== null) {
            throw $failure;
        }

        return $this->select($verified);
    }

    private function select(array $releases): ?array
    {
        $alpha = app(UpdateChannel::class)->current() === 'alpha';
        usort($releases, function ($left, $right) use ($alpha): int {
            return version_compare($this->rank($right, $alpha), $this->rank($left, $alpha)) ?:
                version_compare($right['version'], $left['version']);
        });
        $blocked = null;
        foreach ($releases as $release) {
            if ($release['state'] === 'available') {
                return $blocked === null
                    ? $release
                    : $release + ['blocked' => Arr::only($blocked, ['version', 'channel', 'requirements', 'unmet', 'release_url'])];
            }
            if ($release['state'] === 'incompatible') {
                $blocked ??= $release;
            }
        }

        return $blocked ?? $releases[0] ?? null;
    }

    private function rank(array $release, bool $alpha): string
    {
        return $alpha ? ($release['build']['version'] ?? $release['version']) : $release['version'];
    }

    /**
     * @throws SodiumException
     * @throws JsonException
     * @throws ConnectionException
     * @throws RequestException
     */
    private function release(mixed $release): array
    {
        $version =
            is_array($release) && is_string($release['tag_name'] ?? null)
                ? preg_replace('/^v/', '', $release['tag_name'])
                : null;
        $channel = ReleaseVersion::channel($version);
        if (
            !is_array($release) ||
            !empty($release['draft']) ||
            $channel === null ||
            ($release['prerelease'] ?? null) !== ($channel !== 'stable') ||
            !app(UpdateChannel::class)->allows($channel) ||
            empty($release['published_at']) ||
            !is_array($release['assets'] ?? null)
        ) {
            throw new RuntimeException('Invalid CMS release.');
        }
        $assets = collect($release['assets']);
        $manifestAsset = $assets->where('name', config('updates.manifest_asset'))->sole();
        $signatureAsset = $assets->where('name', config('updates.signature_asset'))->sole();
        $raw = $this->assetBytes($manifestAsset, 262144, $release['tag_name']);
        $signature = base64_decode(trim($this->assetBytes($signatureAsset, 1024, $release['tag_name'])), true);
        $key = base64_decode(app(SigningKeys::class)->publicKey(config('manifest.update.signing_key')), true);
        if (
            !$key ||
            strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ||
            !$signature ||
            strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES ||
            !sodium_crypto_sign_verify_detached($signature, $raw, $key)
        ) {
            throw new RuntimeException('CMS release metadata signature rejected.');
        }
        $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            throw new RuntimeException('Invalid CMS release metadata.');
        }
        $manifestChannel = app(UpdateChannel::class)->validate($manifest);
        $build = ReleaseBuild::manifest($manifest);
        $version = $manifest['version'] ?? null;
        if (
            ($manifest['schema_version'] ?? null) !== 1 ||
            !is_string($version) ||
            $manifestChannel !== $channel ||
            preg_replace('/^v/', '', $release['tag_name']) !== $version ||
            !is_array($manifest['requirements'] ?? null)
        ) {
            throw new RuntimeException('Invalid CMS release metadata.');
        }
        foreach (['php', 'cms'] as $requirement) {
            if (!is_string($manifest['requirements'][$requirement] ?? null)) {
                throw new RuntimeException('Missing CMS release requirement.');
            }
        }
        $archive = $manifest['archive'] ?? [];
        if (
            !is_string($archive['name'] ?? null) ||
            !preg_match('/^kraftink-update-[A-Za-z0-9.-]+\.zip$/D', $archive['name']) ||
            !is_string($archive['sha256'] ?? null) ||
            !preg_match('/^[a-f0-9]{64}$/D', $archive['sha256']) ||
            !is_int($archive['size'] ?? null) ||
            $archive['size'] < 1 ||
            $archive['size'] > config('updates.max_archive_size')
        ) {
            throw new RuntimeException('Invalid CMS release archive.');
        }
        $asset = $assets->where('name', $archive['name'])->sole();
        $url = $this->assetUrl($asset, $release['tag_name']);
        if (($asset['size'] ?? null) !== $archive['size']) {
            throw new RuntimeException('CMS release archive size disagrees with its signed metadata.');
        }
        $unmet = [];
        foreach (['cms' => config('manifest.version'), 'php' => PHP_VERSION] as $requirement => $current) {
            $required = $manifest['requirements'][$requirement];
            if (!Semver::satisfies($current, $required)) {
                $unmet[$requirement] = ['required' => $required, 'current' => $current];
            }
        }
        $available = version_compare($version, config('manifest.version'), '>') && ReleaseBuild::allows($manifest);

        return [
            'state' => $available ? ($unmet === [] ? 'available' : 'incompatible') : 'current',
            'version' => $version,
            'channel' => $channel,
            'build' => $build,
            'requirements' => $manifest['requirements'],
            'unmet' => $unmet,
            'archive' => $archive + ['url' => $url],
            'release_url' => 'https://github.com/'.$this->repository().'/releases/tag/'.rawurlencode($release['tag_name']),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    public function download(string $expectedVersion): string
    {
        $release = $this->check(true);
        if ($release['state'] !== 'available' || $release['version'] !== $expectedVersion) {
            throw new RuntimeException(
                'The CMS release changed or is no longer compatible. Refresh the release information.',
            );
        }
        $directory = storage_path('app/updates/downloads');
        File::ensureDirectoryExists($directory, 0700);
        chmod($directory, 0700);
        $path = $directory.'/'.bin2hex(random_bytes(16)).'.zip';
        $output = fopen($path, 'xb');
        if (!$output) {
            throw new RuntimeException('Cannot create a private update download.');
        }
        chmod($path, 0600);
        try {
            $response = $this->request($release['archive']['url'], true);
            $response->throw();
            $stream = $response->toPsrResponse()->getBody();
            $size = 0;
            while (!$stream->eof()) {
                $chunk = $stream->read(65536);
                $size += strlen($chunk);
                if (
                    $size > $release['archive']['size'] ||
                    ($chunk !== '' && fwrite($output, $chunk) !== strlen($chunk))
                ) {
                    throw new RuntimeException('Oversized or incomplete CMS update download.');
                }
            }
            $stream->close();
            fclose($output);
            $output = null;
            if (
                $size !== $release['archive']['size'] ||
                !hash_equals($release['archive']['sha256'], hash_file('sha256', $path))
            ) {
                throw new RuntimeException('CMS update checksum rejected.');
            }

            return $path;
        } catch (Throwable $exception) {
            if (is_resource($output)) {
                fclose($output);
            }
            File::delete($path);
            throw $exception;
        }
    }

    /**
     * @throws ConnectionException
     */
    private function request(string $url, bool $stream = false): Response
    {
        for ($redirects = 0; $redirects <= 4; $redirects++) {
            $timeout = $this->deadline === null ? 120 : $this->deadline - microtime(true);
            if ($timeout <= 0) {
                throw new RuntimeException('CMS release check time budget exceeded.');
            }
            $parts = parse_url($url);
            if (
                !$parts ||
                ($parts['scheme'] ?? '') !== 'https' ||
                !in_array(
                    strtolower($parts['host'] ?? ''),
                    [
                        'api.github.com',
                        'github.com',
                        'raw.githubusercontent.com',
                        'objects.githubusercontent.com',
                        'release-assets.githubusercontent.com',
                    ],
                    true,
                ) ||
                isset($parts['user']) ||
                isset($parts['pass']) ||
                isset($parts['port'])
            ) {
                throw new RuntimeException('Untrusted CMS release URL.');
            }
            $response = Http::acceptJson()
                ->withHeaders(['User-Agent' => 'KraftInk-CMS', 'X-GitHub-Api-Version' => '2026-03-10'])
                ->connectTimeout(min(10, $timeout))
                ->timeout($timeout)
                ->withOptions([
                    'allow_redirects' => false,
                    'stream' => $stream,
                    ...$this->deadline !== null ? ['read_timeout' => min(1, $timeout)] : [],
                ])
                ->get($url);
            if (!$response->redirect()) {
                return $response;
            }
            $url = $response->header('Location');
        }
        throw new RuntimeException('Too many CMS release redirects.');
    }

    /**
     * @throws RequestException
     */
    private function bytes(Response $response, int $limit): string
    {
        $response->throw();
        $body = $response->toPsrResponse()->getBody();
        $raw = '';
        try {
            while (!$body->eof() && strlen($raw) <= $limit) {
                if ($this->deadline !== null && microtime(true) >= $this->deadline) {
                    throw new RuntimeException('CMS release check time budget exceeded.');
                }
                $raw .= $body->read(min(65536, $limit + 1 - strlen($raw)));
            }
        } finally {
            $body->close();
        }
        if (strlen($raw) > $limit) {
            throw new RuntimeException('Oversized CMS release metadata.');
        }

        return $raw;
    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    private function assetBytes(array $asset, int $limit, string $tag): string
    {
        if (!is_int($asset['size'] ?? null) || $asset['size'] > $limit || $asset['size'] < 1) {
            throw new RuntimeException('Invalid CMS release metadata asset size.');
        }

        return $this->bytes($this->request($this->assetUrl($asset, $tag), true), $limit);
    }

    private function assetUrl(array $asset, string $tag): string
    {
        $url = $asset['browser_download_url'] ?? null;
        $path = '/releases/download/'.rawurlencode($tag).'/'.rawurlencode($asset['name']);
        if (
            !is_string($url) ||
            !preg_match(
                '~^https://github\\.com/(?i:'.preg_quote($this->repository(), '~').')'.preg_quote($path, '~').'$~D',
                $url,
            )
        ) {
            throw new RuntimeException('The CMS release asset belongs to another source.');
        }

        return $url;
    }

    private function remember(array $release): array
    {
        Cache::put($this->cacheKey(), $release, (int) config('updates.cache_seconds', 600));

        return $release;
    }

    private function cacheKey(): string
    {
        return 'kraft.updates.github.'.
            hash(
                'sha256',
                implode('|', [
                    $this->repository(),
                    config('manifest.version'),
                    config('manifest.release.version'),
                    config('manifest.release.sha'),
                    PHP_VERSION,
                    app(UpdateChannel::class)->current(),
                    config('manifest.update.signing_key'),
                    app(SigningKeys::class)->publicKey(config('manifest.update.signing_key')),
                ]),
            );
    }
}
