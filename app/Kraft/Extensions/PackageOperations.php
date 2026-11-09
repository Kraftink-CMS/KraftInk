<?php

namespace App\Kraft\Extensions;

use Illuminate\Support\Facades\File;
use RuntimeException;

class PackageOperations
{
    public function archive(string $archive, ?array $market = null): string
    {
        $installer = app(PackageInstaller::class);
        $package = $installer->extract(
            $archive,
            $market['resource']['slug'] ?? null,
            $market['version']['version'] ?? null,
            $market['resource']['type'] ?? null,
            $market['version']['requirements'] ?? null,
            $market === null ? null : GameSupport::declarations($market['version']),
        );
        try {
            $manifest = $package['manifest'];
            $provenance =
                $market === null
                    ? null
                    : app(MarketSecurity::class)->provenance(
                        $market,
                        hash_file('sha256', $package['directory'].'/manifest.json'),
                    );
            $existing = app(ExtensionManager::class)->all()[$manifest['id']] ?? null;
            if (
                $existing &&
                ($existing['type'] !== $manifest['type'] || (!$existing['valid'] && $existing['path'] !== null))
            ) {
                throw new RuntimeException(__('system.extensions.identifier_mismatch'));
            }
            if ($existing && $existing['path'] === null) {
                if (version_compare($manifest['version'], $existing['installed_version'], '<')) {
                    throw new RuntimeException(__('system.extensions.update_version'));
                }
                app(ExtensionManager::class)->assertInstallable($manifest);
                $installer->install(
                    $archive,
                    $manifest['id'],
                    $manifest['version'],
                    $manifest['type'],
                    $market['version']['requirements'] ?? null,
                    $market === null ? null : GameSupport::declarations($market['version']),
                );

                return app(ExtensionInstaller::class)->stage(
                    $manifest['id'],
                    [],
                    $provenance,
                    hash_file('sha256', $package['directory'].'/manifest.json'),
                );
            }
            if ($existing && $existing['installed']) {
                return app(ExtensionUpdateManager::class)->stage($archive, $market);
            }
            app(ExtensionManager::class)->assertInstallable($manifest);
            if ($existing) {
                return app(ExtensionUpdateManager::class)->stage($archive, $market, true);
            }
            $installer->install(
                $archive,
                $manifest['id'],
                $manifest['version'],
                $manifest['type'],
                $market['version']['requirements'] ?? null,
                $market === null ? null : GameSupport::declarations($market['version']),
            );

            return app(ExtensionInstaller::class)->stage($manifest['id'], [], $provenance);
        } finally {
            File::deleteDirectory($package['cleanup']);
        }
    }

    public function market(string $id, ?string $version, string $source): string
    {
        $package = app(Market::class)->package($id, $version, $source);
        $manager = app(ExtensionManager::class);
        $existing = $manager->all()[$package['resource']['slug']] ?? null;
        if ($existing && $existing['installed'] && $existing['path'] !== null) {
            if (!$existing['valid'] || $existing['type'] !== $package['resource']['type']) {
                throw new RuntimeException(__('system.extensions.identifier_mismatch'));
            }
            if (!version_compare($package['version']['version'], $existing['installed_version'], '>')) {
                throw new RuntimeException(__('system.extensions.update_version'));
            }
            $manager->assertReplacement([
                'id' => $package['resource']['slug'],
                'type' => $package['resource']['type'],
                'target' => $existing['target'] ?? null,
                'version' => $package['version']['version'],
                'requires' => $package['version']['requirements'],
            ] + GameSupport::declarations($package['version']));
        }
        $archive = app(PackageInstaller::class)->downloadArchive(
            $package['version']['download']['url'],
            $package['version']['download']['sha256'],
            $package['source']['id'],
        );
        try {
            return $this->archive($archive, $package);
        } finally {
            File::delete($archive);
        }
    }
}
