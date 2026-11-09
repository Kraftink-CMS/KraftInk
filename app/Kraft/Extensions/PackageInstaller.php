<?php

namespace App\Kraft\Extensions;

use App\Exceptions\MarketRequestFailed;
use Composer\Semver\VersionParser;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use ZipArchive;

class PackageInstaller
{
    public function downloadArchive(string $url, string $sha256, ?string $trustedSourceId = null): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/Di', $sha256)) {
            throw new RuntimeException(__('system.extensions.checksum_required'));
        }
        $url = app(Market::class)->packageUrl($url, $trustedSourceId);
        $path = storage_path('app/package-'.bin2hex(random_bytes(12)).'.zip');
        File::ensureDirectoryExists(dirname($path));
        try {
            for ($redirects = 0; $redirects <= 3; $redirects++) {
                $response = Http::timeout(90)
                    ->connectTimeout(10)
                    ->withHeaders(app(MarketAccess::class)->headers($url, $trustedSourceId))
                    ->withOptions([
                        'sink' => $path,
                        'allow_redirects' => false,
                        'on_headers' => function ($response): void {
                            if ((int) $response->getHeaderLine('Content-Length') > 52428800) {
                                throw new RuntimeException(__('system.extensions.archive_unsafe'));
                            }
                        },
                        'progress' => function ($total, $downloaded): void {
                            if ($downloaded > 52428800) {
                                throw new RuntimeException(__('system.extensions.archive_unsafe'));
                            }
                        },
                    ])
                    ->get($url);
                app(Market::class)->checkResponse($response);
                if (!$response->redirect()) {
                    if ($response->status() !== 200) {
                        throw new RuntimeException(__('system.extensions.archive_invalid'));
                    }
                    break;
                }
                if ($redirects === 3 || !$response->header('Location')) {
                    throw new RuntimeException(__('system.extensions.download_untrusted'));
                }
                $uri = UriResolver::resolve(new Uri($url), new Uri($response->header('Location')));
                if (
                    $uri->getScheme() !== 'https' ||
                    !app(Market::class)->allowedDownloadHost($uri->getHost(), $trustedSourceId) ||
                    $uri->getUserInfo() !== '' ||
                    $uri->getPort() !== null
                ) {
                    throw new RuntimeException(__('system.extensions.download_untrusted'));
                }
                $url = (string) $uri;
            }
            if (
                !is_file($path) ||
                filesize($path) > 52428800 ||
                !hash_equals(strtolower($sha256), hash_file('sha256', $path))
            ) {
                throw new RuntimeException(__('system.extensions.checksum_failed'));
            }

            return $path;
        } catch (Throwable $error) {
            File::delete($path);
            if ($error instanceof MarketRequestFailed && $error->status >= 400 && $error->status < 500 && $trustedSourceId !== null) {
                app(Market::class)->deny($trustedSourceId, $error);
            }
            throw $error;
        }
    }

    public function install(
        string $path,
        ?string $expectedId = null,
        ?string $expectedVersion = null,
        ?string $expectedType = null,
        ?array $expectedRequirements = null,
        ?array $expectedGameSupport = null,
    ): void {
        $package = $this->extract($path, $expectedId, $expectedVersion, $expectedType, $expectedRequirements, $expectedGameSupport);
        $lock = null;
        try {
            $manifest = $package['manifest'];
            File::ensureDirectoryExists(storage_path('app'));
            $lock = fopen(storage_path('app/extensions.lock'), 'c+');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException(__('system.extensions.update_busy'));
            }
            app(InstallationReceipts::class)->refresh();
            if (!empty(app(InstallationReceipts::class)->all()[$manifest['id']]['retired_uuid'])) {
                throw new RuntimeException(__('system.extensions.restore_before_install'));
            }
            $root = config(
                match ($manifest['type']) {
                    'plugin' => 'kraft.extensions.installed.plugins',
                    'game' => 'kraft.extensions.installed.games',
                    default => 'kraft.extensions.installed.themes',
                },
            );
            $target = $root.'/'.$manifest['id'];
            if (file_exists($target)) {
                throw new RuntimeException(__('system.extensions.already_installed'));
            }
            File::ensureDirectoryExists($root);
            if (!File::moveDirectory($package['directory'], $target)) {
                throw new RuntimeException(__('system.extensions.install_failed'));
            }
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            File::deleteDirectory($package['cleanup']);
        }
    }

    public function extract(
        string $path,
        ?string $expectedId = null,
        ?string $expectedVersion = null,
        ?string $expectedType = null,
        ?array $expectedRequirements = null,
        ?array $expectedGameSupport = null,
    ): array {
        $zip = new ZipArchive();
        if (!is_file($path) || filesize($path) > 52428800 || $zip->open($path) !== true) {
            throw new RuntimeException(__('system.extensions.archive_invalid'));
        }
        $stage = null;
        try {
            $stage = storage_path('app/staging/'.bin2hex(random_bytes(12)));
            File::ensureDirectoryExists($stage, 0700);
            $size = 0;
            $seen = [];
            if ($zip->numFiles > 10000) {
                throw new RuntimeException(__('system.extensions.archive_unsafe'));
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                $name = $entry['name'];
                $size += $entry['size'];
                $zip->getExternalAttributesIndex($index, $system, $attributes);
                $key = strtolower(rtrim($name, '/'));
                $segments = explode('/', rtrim($name, '/'));
                if (
                    $size > 104857600 ||
                    isset($seen[$key]) ||
                    $name === '' ||
                    str_contains($name, '\\') ||
                    str_contains($name, ':') ||
                    str_starts_with($name, '/') ||
                    preg_match('/[\x00-\x1f\x7f]/', $name) ||
                    (($attributes >> 16) & 0170000) === 0120000
                ) {
                    throw new RuntimeException(__('system.extensions.archive_unsafe'));
                }
                foreach ($segments as $segment) {
                    if (
                        $segment === '' ||
                        $segment === '.' ||
                        $segment === '..' ||
                        preg_match('/[. ]$/D', $segment) ||
                        preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $segment)
                    ) {
                        throw new RuntimeException(__('system.extensions.archive_unsafe'));
                    }
                }
                $seen[$key] = true;
            }
            if (!$zip->extractTo($stage)) {
                throw new RuntimeException(__('system.extensions.archive_invalid'));
            }
            $source = $stage;
            if (!is_file($source.'/manifest.json')) {
                $children = array_values(array_diff(scandir($source), ['.', '..']));
                if (count($children) !== 1 || !is_dir($source.'/'.$children[0])) {
                    throw new RuntimeException(__('system.extensions.archive_manifest'));
                }
                $source .= '/'.$children[0];
            }
            $manifest = app(Manifest::class)->read($source, $expectedType, $expectedId);
            if ($expectedVersion && !version_compare($manifest['version'], $expectedVersion, '==')) {
                throw new RuntimeException(__('system.extensions.market_package_mismatch'));
            }
            if (($manifest['builtin'] ?? false) || app(BuiltinExtensions::class)->contains($manifest['id'])) {
                throw new RuntimeException(__('system.extensions.archive_builtin'));
            }
            if ($expectedRequirements !== null) {
                $actual = app(DependencyConstraints::class)->requirements($manifest);
                if ($this->requirements($actual) !== $this->requirements($expectedRequirements)) {
                    throw new RuntimeException(__('system.extensions.market_requirements_mismatch'));
                }
            }
            if ($expectedGameSupport !== null && !GameSupport::same($manifest, $expectedGameSupport, $manifest['type'])) {
                throw new RuntimeException(__('system.extensions.market_games_mismatch'));
            }
            app(ExtensionManager::class)->assertInstallable($manifest);

            return ['directory' => $source, 'cleanup' => $stage, 'manifest' => $manifest];
        } catch (Throwable $error) {
            if ($stage !== null && is_dir($stage)) {
                File::deleteDirectory($stage);
            }
            throw $error;
        } finally {
            $zip->close();
        }
    }

    private function requirements(array $requirements): array
    {
        $normalized = array_map(fn ($constraint) => (string) (new VersionParser())->parseConstraints($constraint), app(DependencyConstraints::class)->map($requirements, true));
        ksort($normalized);

        return $normalized;
    }
}
