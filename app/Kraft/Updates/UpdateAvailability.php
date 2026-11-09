<?php

namespace App\Kraft\Updates;

use App\Kraft\SigningKeys;
use App\Kraft\Extensions\ExtensionManager;
use App\Kraft\Extensions\BuiltinExtensions;
use App\Kraft\Extensions\InstallationReceipts;
use App\Kraft\Extensions\Market;
use App\Kraft\Extensions\MarketAccess;
use App\Models\Accounts\User;
use Composer\Semver\VersionParser;
use Illuminate\Support\Facades\Cache;
use Throwable;
use JsonException;

class UpdateAvailability
{
    /**
     * @throws JsonException
     */
    public function forUser(User $user): array
    {
        $response = [
            'cms' => null,
            'plugins' => [],
            'themes' => [],
            'games' => [],
            'checked_at' => null,
            'refresh_after' => 600,
        ];
        if ($user->hasPermission('updates.manage')) {
            $cms = $this->cached('cms', $this->identity(), function (): array {
                $release = app(GitHubReleaseRepository::class)->checkForNotifications();

                return [
                    'data' => ($release['state'] ?? null) === 'available'
                            ? [
                                'version' => $release['version'],
                                'url' => route('admin.updates.index', absolute: false),
                            ]
                            : null,
                ];
            });
            $response['cms'] = $cms['data'];
            $response['checked_at'] = $cms['checked_at'];
            $response['refresh_after'] = min($response['refresh_after'], $cms['refresh_after']);
        }
        if ($user->hasPermission('extensions.manage')) {
            $receipts = app(InstallationReceipts::class)->all();
            $extensions = $this->cached(
                'extensions',
                [...$this->identity(), ...$this->extensionIdentity($receipts)],
                fn () => $this->extensions($receipts),
            );
            $response['plugins'] = $extensions['data']['plugins'] ?? [];
            $response['themes'] = $extensions['data']['themes'] ?? [];
            $response['games'] = $extensions['data']['games'] ?? [];
            $response['checked_at'] ??= $extensions['checked_at'];
            $response['refresh_after'] = min($response['refresh_after'], $extensions['refresh_after']);
        }

        return $response;
    }

    private function identity(): array
    {
        return [
            'version' => config('manifest.version'),
            'release_build' => config('manifest.release.version'),
            'release_commit' => config('manifest.release.sha'),
            'php' => PHP_VERSION,
            'channel' => app(UpdateChannel::class)->current(),
            'repository' => config('manifest.update.repository'),
            'signing_key' => config('manifest.update.signing_key'),
            'key' => app(SigningKeys::class)->publicKey(config('manifest.update.signing_key')),
        ];
    }

    private function extensionIdentity(array $receipts): array
    {
        ksort($receipts);
        $files = [];
        foreach ($receipts as $id => $receipt) {
            if (!preg_match('/^[a-z][a-z0-9-]{1,49}$/D', $id)) {
                continue;
            }
            $root = match ($receipt['type']) {
                'plugin' => config('kraft.extensions.installed.plugins'),
                'theme' => config('kraft.extensions.installed.themes'),
                default => config('kraft.extensions.installed.games'),
            };
            $path = $root.'/'.$id.'/manifest.json';
            $files[$id] = is_file($path) ? [filemtime($path), filesize($path)] : null;
        }

        return [
            'receipts' => $receipts,
            'files' => $files,
            'generation' => app(InstallationReceipts::class)->generation(),
            'sources' => config('market.sources'),
            'market_access' => array_map(
                fn ($sourceId) => app(MarketAccess::class)->cacheIdentity($sourceId),
                array_keys(app(Market::class)->sources()),
            ),
            'schema' => config('market.schema_version'),
            'market_generation' => Cache::get('kraft.market.generation'),
            'market_cache_seconds' => config('market.cache_seconds', 60),
            'builtins' => config('manifest.bundled'),
            'roots' => config('kraft.extensions'),
            'theme' => app(ExtensionManager::class)->selectedTheme(),
            'admin_theme' => app(ExtensionManager::class)->selectedTheme('admin'),
            'locale' => app()->getLocale(),
        ];
    }

    /**
     * @throws JsonException
     */
    private function cached(string $component, array $identity, callable $load): array
    {
        $seconds = $component === 'extensions' ? max(1, min(600, (int) config('market.cache_seconds', 60))) : 600;
        $key =
            'kraft.updates.availability.'.
            $component.
            '.'.
            hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
        if ($entry = Cache::get($key.'.fresh')) {
            return $entry + ['refresh_after' => max(1, $entry['expires_at'] - now()->timestamp)];
        }
        $stale = Cache::get($key.'.stale');
        if (Cache::has($key.'.retry')) {
            return ($stale ?? ['data' => null, 'checked_at' => null]) + ['refresh_after' => 60];
        }
        $lock = Cache::lock($key.'.lock', 30);
        if (!$lock->get()) {
            return ($stale ?? ['data' => null, 'checked_at' => null]) + ['refresh_after' => 15];
        }
        try {
            if ($entry = Cache::get($key.'.fresh')) {
                return $entry + ['refresh_after' => max(1, $entry['expires_at'] - now()->timestamp)];
            }
            $result = $load();
            $entry = [
                'data' => $result['data'],
                'checked_at' => now()->toIso8601String(),
                'expires_at' => now()->timestamp + $seconds,
            ];
            if (($result['fresh'] ?? true) === true) {
                Cache::put($key.'.fresh', $entry, $seconds);
                Cache::put($key.'.stale', $entry, 86400);

                return $entry + ['refresh_after' => $seconds];
            }
            Cache::put($key.'.retry', true, 60);
            $entry = $stale ?? $entry;
            if ($stale === null) {
                Cache::put($key.'.stale', $entry, 86400);
            }

            return $entry + ['refresh_after' => 60];
        } catch (Throwable $exception) {
            report($exception);
            Cache::put($key.'.retry', true, 60);

            return ($stale ?? ['data' => null, 'checked_at' => null]) + ['refresh_after' => 60];
        } finally {
            $lock->release();
        }
    }

    private function extensions(array $receipts): array
    {
        $installed = array_filter(
            $receipts,
            fn ($receipt) => empty($receipt['retired_uuid']) &&
                in_array($receipt['type'], ['plugin', 'theme', 'game'], true) &&
                !app(BuiltinExtensions::class)->contains($receipt['id']),
        );
        $updates = ['plugins' => [], 'themes' => [], 'games' => []];
        if ($installed === [] || app(Market::class)->sources() === []) {
            return ['data' => $updates];
        }
        $market = app(Market::class);
        $listing = $market->listingForNotifications();
        $manager = app(ExtensionManager::class);
        $extensions = $manager->all();
        $candidates = [];
        foreach ($listing['resources'] as $resource) {
            if (
                ($resource['_source_state'] ?? 'fresh') !== 'fresh' ||
                ($resource['access']['allowed'] ?? true) === false
            ) {
                continue;
            }
            $id = $resource['slug'];
            $receipt = $installed[$id] ?? null;
            $extension = $extensions[$id] ?? null;
            if (
                !$receipt ||
                !$extension ||
                !$extension['valid'] ||
                !$extension['manifest_current'] ||
                $receipt['type'] !== $resource['type']
            ) {
                continue;
            }
            foreach ($resource['versions'] as $version) {
                if (
                    app(UpdateChannel::class)->allows(VersionParser::parseStability($version['version'])) &&
                    version_compare($version['version'], $receipt['version'], '>')
                ) {
                    $candidates[$id][] = ['resource' => $resource, 'version' => $version];
                }
            }
        }
        foreach ($candidates as $id => $versions) {
            usort(
                $versions,
                fn ($left, $right) => version_compare($right['version']['version'], $left['version']['version']),
            );
            foreach ($versions as $candidate) {
                $resource = $candidate['resource'];
                $version = $candidate['version'];
                try {
                    $manager->assertReplacement(
                        [
                            'id' => $id,
                            'type' => $resource['type'],
                            'target' => $extensions[$id]['target'] ?? null,
                            'version' => $version['version'],
                            'requires' => $version['requirements'],
                        ],
                        $extensions,
                    );
                } catch (Throwable) {
                    continue;
                }
                $group = match ($resource['type']) {
                    'plugin' => 'plugins',
                    'theme' => 'themes',
                    'game' => 'games',
                };
                $route = match ($resource['type']) {
                    'plugin' => 'admin.extensions.plugins.index',
                    'theme' => 'admin.extensions.themes.index',
                    'game' => 'admin.games.index',
                };
                $updates[$group][] = [
                    'id' => $id,
                    'name' => __($extensions[$id]['name']),
                    'version' => $version['version'],
                    'url' => route($route, absolute: false),
                    'catalogue_id' => $resource['id'],
                    'source' => $resource['_source_id'],
                ];
                break;
            }
        }

        $denied = in_array('denied', array_column($listing['sources'] ?? [], 'state'), true);

        return ['data' => $updates, 'fresh' => $denied || in_array($listing['state'], ['fresh', 'unconfigured'], true)];
    }
}
