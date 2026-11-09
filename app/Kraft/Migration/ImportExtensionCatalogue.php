<?php

namespace App\Kraft\Migration;

use App\Kraft\Extensions\BuiltinExtensions;
use App\Kraft\Extensions\ExtensionManager;
use App\Kraft\Extensions\InstallationReceipts;
use App\Kraft\Extensions\Market;
use App\Kraft\Extensions\PackageOperations;
use App\Kraft\Updates\UpdateChannel;
use App\Kraft\Updates\UpdateManager;
use Composer\Semver\VersionParser;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

final class ImportExtensionCatalogue
{
    public function discover(string $source, Connection $sourceDb, array $tableDefinitions): array
    {
        $listing = app(Market::class)->listing();
        $local = app(ExtensionManager::class)->all();
        $names = $this->sourceNames($source, $sourceDb, $tableDefinitions);
        $unavailable = [];
        $matches = [];
        $core = array_keys(
            array_filter(app(MappingRegistry::class)->mappings($source), fn ($mapping) => $mapping['plugin'] === null),
        );
        $tables = array_diff(array_keys($tableDefinitions), $core);
        foreach ($listing['resources'] as $resource) {
            if (!in_array($resource['type'], ['plugin', 'theme'], true)) {
                continue;
            }
            $evidence = $this->match($resource, $source, $names, $tables);
            if ($evidence === null) {
                continue;
            }
            if (isset($local[$resource['slug']])) {
                $extension = $local[$resource['slug']];
                if (!$extension['valid'] || $extension['type'] !== $resource['type']) {
                    $unavailable[] = ['type' => $resource['type'], 'name' => $evidence, 'reason' => 'local_conflict'];
                }

                continue;
            }
            if (app(BuiltinExtensions::class)->contains($resource['slug'])) {
                continue;
            }
            $matches[$resource['type'].':'.$evidence][] = $resource;
        }
        $candidates = [];
        foreach ($matches as $key => $resources) {
            [$type, $name] = explode(':', $key, 2);
            if (count($resources) !== 1) {
                $unavailable[] = ['type' => $type, 'name' => $name, 'reason' => 'ambiguous_catalogue'];

                continue;
            }
            $resource = $resources[0];
            $reason = $this->unavailableReason($resource);
            $version = $reason === null ? $this->version($resource) : null;
            if ($version === null) {
                $unavailable[] = ['type' => $type, 'name' => $name, 'reason' => $reason ?? 'incompatible'];

                continue;
            }
            $candidates[] = [
                'id' => $resource['id'],
                'slug' => $resource['slug'],
                'type' => $type,
                'source' => $resource['_source_id'],
                'version' => $version['version'],
                'source_cms' => $source,
                'source_name' => $name,
                'select' => $type === 'theme' && in_array($name, $names['theme'], true),
            ];
        }
        $grouped = [];
        foreach ($candidates as $candidate) {
            $grouped[$candidate['slug']][] = $candidate;
        }
        $candidates = [];
        foreach ($grouped as $items) {
            if (count($items) > 1) {
                foreach ($items as $item) {
                    $unavailable[] = [
                        'type' => $item['type'],
                        'name' => $item['source_name'],
                        'reason' => 'ambiguous_catalogue',
                    ];
                }

                continue;
            }
            $candidates[] = $items[0];
        }
        foreach ($names as $type => $sourceNames) {
            foreach ($sourceNames as $name) {
                if (
                    isset($matches[$type.':'.$name]) ||
                    app(ImportExtensions::class)->equivalent($name, $type, $source) !== null
                ) {
                    continue;
                }
                $unavailable[] = ['type' => $type, 'name' => $name, 'reason' => 'not_found'];
            }
        }

        return [
            'candidates' => $candidates,
            'unavailable' => array_values(array_unique($unavailable, SORT_REGULAR)),
            'state' => $listing['state'],
        ];
    }

    public function install(array $candidates): array
    {
        $installed = [];
        $failed = [];
        foreach ($candidates as $candidate) {
            $identity = [
                'type' => is_string($candidate['type'] ?? null) ? $candidate['type'] : 'plugin',
                'name' => is_string($candidate['source_name'] ?? null)
                    ? mb_substr($candidate['source_name'], 0, 120)
                    : '',
            ];
            try {
                $this->validateCandidate($candidate);
                $manager = app(ExtensionManager::class);
                if (isset($manager->all()[$candidate['slug']])) {
                    throw new RuntimeException('local_conflict');
                }
                $package = app(Market::class)->package($candidate['id'], $candidate['version'], $candidate['source']);
                if (
                    $package['resource']['slug'] !== $candidate['slug'] ||
                    $package['resource']['type'] !== $candidate['type'] ||
                    $package['version']['version'] !== $candidate['version'] ||
                    ($package['resource']['commercial']['kind'] ?? null) !== 'free' ||
                    !empty($package['resource']['manual_only']) ||
                    !app(UpdateChannel::class)->allows(VersionParser::parseStability($candidate['version']))
                ) {
                    throw new RuntimeException('catalogue_changed');
                }
                $manager->assertMarketRequirements($package['resource'], $package['version']);
                $run = app(PackageOperations::class)->market(
                    $candidate['id'],
                    $candidate['version'],
                    $candidate['source'],
                );
                $process = app(UpdateManager::class)->run($run);
                app(InstallationReceipts::class)->refresh();
                if (!$process->isSuccessful() || app(UpdateManager::class)->status($run)['state'] !== 'completed') {
                    throw new RuntimeException('installation_failed');
                }
                $manager->refreshVerified($candidate['slug']);
                $extension = $manager->all()[$candidate['slug']] ?? null;
                if (
                    !$extension ||
                    !$extension['valid'] ||
                    !$extension['installed'] ||
                    !$extension['manifest_current'] ||
                    $extension['type'] !== $candidate['type'] ||
                    $extension['version'] !== $candidate['version']
                ) {
                    throw new RuntimeException('installation_failed');
                }
                if ($candidate['type'] === 'theme') {
                    if (
                        $extension['target'] !== 'site' ||
                        !$candidate['select'] ||
                        !$this->matchesName($extension, $candidate['source_cms'], $candidate['source_name'])
                    ) {
                        throw new RuntimeException('theme_mismatch');
                    }
                    $manager->selectTheme($candidate['slug']);
                } elseif (!$extension['enabled']) {
                    $manager->toggle($candidate['slug'], true);
                }
                $installed[] = ['id' => $candidate['slug'], 'type' => $candidate['type'], 'run' => $run];
            } catch (Throwable $exception) {
                report($exception);
                $known = [
                    'local_conflict',
                    'catalogue_changed',
                    'installation_failed',
                    'theme_mismatch',
                    'invalid_candidate',
                ];
                $failed[] = $identity + [
                    'reason' => in_array($exception->getMessage(), $known, true)
                        ? $exception->getMessage()
                        : 'installation_failed',
                ];
            }
        }

        return compact('installed', 'failed');
    }

    private function sourceNames(string $source, Connection $connection, array $tables): array
    {
        /** @var SourceAdapters $sourceAdapters */
        $sourceAdapters = app(SourceAdapters::class);
        $names = $sourceAdapters->get($source)->extensionNames($connection, $tables);
        $result = ['plugin' => [], 'theme' => []];
        foreach ($result as $type => $_) {
            foreach ($names[$type] as $name) {
                if (is_string($name) && ($slug = $this->slug($name)) !== null) {
                    $result[$type][] = $slug;
                }
            }
            $result[$type] = array_values(array_unique($result[$type]));
        }

        return $result;
    }

    private function match(array $resource, string $source, array $names, array $tables): ?string
    {
        foreach ($names[$resource['type']] as $name) {
            if ($this->matchesName($resource, $source, $name)) {
                return $name;
            }
        }
        if ($resource['type'] !== 'plugin') {
            return null;
        }
        $prefixes = [$resource['slug'].'_'];
        foreach ($this->aliases($resource, $source) as $prefix) {
            if (is_string($prefix) && preg_match('/^[a-z][a-z0-9_]{1,99}$/D', $prefix) && str_contains($prefix, '_')) {
                $prefixes[] = $prefix;
            }
        }
        foreach ($prefixes as $prefix) {
            if (array_any($tables, fn ($table) => str_starts_with($table, $prefix))) {
                return rtrim($prefix, '_');
            }
        }

        return null;
    }

    private function matchesName(array $resource, string $source, string $name): bool
    {
        if (($resource['slug'] ?? ($resource['id'] ?? null)) === $name) {
            return true;
        }

        return array_any(
            $this->aliases($resource, $source),
            fn ($alias) => is_string($alias) && $this->slug(rtrim($alias, '_')) === $name,
        );
    }

    private function aliases(array $resource, string $source): array
    {
        $imports = $resource['imports'] ?? [];
        if (!is_array($imports) || !is_array($imports[$source] ?? null) || count($imports[$source]) > 50) {
            return [];
        }

        return array_values(array_filter($imports[$source], 'is_string'));
    }

    private function unavailableReason(array $resource): ?string
    {
        if ($resource['type'] === 'theme' && ($resource['target'] ?? 'site') !== 'site') {
            return 'theme_mismatch';
        }
        if (($resource['_source_state'] ?? null) !== 'fresh') {
            return 'catalogue_unavailable';
        }
        if (($resource['commercial']['kind'] ?? null) !== 'free') {
            return 'paid';
        }
        if (!empty($resource['manual_only'])) {
            return 'manual_only';
        }

        return null;
    }

    private function version(array $resource): ?array
    {
        $versions = $resource['versions'];
        usort($versions, fn ($left, $right) => version_compare($right['version'], $left['version']));
        foreach ($versions as $version) {
            try {
                if (!app(UpdateChannel::class)->allows(VersionParser::parseStability($version['version']))) {
                    continue;
                }
                app(ExtensionManager::class)->assertMarketRequirements($resource, $version);

                return $version;
            } catch (Throwable) {
            }
        }

        return null;
    }

    private function validateCandidate(array $candidate): void
    {
        if (
            !is_string($candidate['id'] ?? null) ||
            !preg_match('/^[a-z][a-z0-9-]{1,49}\/[a-z][a-z0-9-]{1,49}$/D', $candidate['id']) ||
            !is_string($candidate['slug'] ?? null) ||
            !preg_match('/^[a-z][a-z0-9-]{1,49}$/D', $candidate['slug']) ||
            !in_array($candidate['type'] ?? null, ['plugin', 'theme'], true) ||
            !is_string($candidate['source'] ?? null) ||
            !preg_match('/^[a-z][a-z0-9-]{0,49}$/D', $candidate['source']) ||
            !is_string($candidate['version'] ?? null) ||
            strlen($candidate['version']) > 80 ||
            !is_string($candidate['source_cms'] ?? null) ||
            !app(SourceAdapters::class)->has($candidate['source_cms']) ||
            !is_string($candidate['source_name'] ?? null) ||
            $this->slug($candidate['source_name']) === null ||
            !is_bool($candidate['select'] ?? null)
        ) {
            throw new RuntimeException('invalid_candidate');
        }
    }

    private function slug(string $value): ?string
    {
        $value = strtolower(trim($value));

        return preg_match('/^[a-z][a-z0-9_-]{1,99}$/D', $value) ? $value : null;
    }
}
