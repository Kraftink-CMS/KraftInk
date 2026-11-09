<?php

namespace App\Kraft\Extensions;

use App\Kraft\Migration\ImportManager;
use App\Kraft\Updates\UpdatePreparation;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;
use KraftUpdateFiles;

class ExtensionUpdateManager
{
    public function stage(string $archive, ?array $market = null, bool $installation = false): string
    {
        if (app(ImportManager::class)->isRunning()) {
            throw new RuntimeException(__('system.extensions.update_busy'));
        }
        $package = app(PackageInstaller::class)->extract(
            $archive,
            $market['resource']['slug'] ?? null,
            $market['version']['version'] ?? null,
            $market['resource']['type'] ?? null,
            $market['version']['requirements'] ?? null,
            $market === null ? null : GameSupport::declarations($market['version']),
        );
        $run = null;
        try {
            $id = date('YmdHis').'-'.bin2hex(random_bytes(6));
            $run = storage_path('app/updates/'.$id);
            $manifest = $package['manifest'];
            $manager = app(ExtensionManager::class);
            $installed = $manager->all()[$manifest['id']] ?? null;
            if (
                !$installed ||
                !$installed['valid'] ||
                !in_array($installed['type'], ['plugin', 'theme', 'game'], true) ||
                $installed['type'] !== $manifest['type']
            ) {
                throw new RuntimeException(__('system.extensions.update_missing'));
            }
            if (!$installation && !($installed['installed'] ?? false)) {
                throw new RuntimeException(__('system.extensions.install_first'));
            }
            if (
                !$installation &&
                !version_compare($manifest['version'], $installed['installed_version'] ?? $installed['version'], '>')
            ) {
                throw new RuntimeException(__('system.extensions.update_version'));
            }
            $installation ? $manager->assertInstallable($manifest) : $manager->assertReplacement($manifest);
            $scope = app(PackagePaths::class)->scope($installed);
            if (!class_exists(KraftUpdateFiles::class, false)) {
                require_once base_path('updater/files.php');
            }
            KraftUpdateFiles::configure(config('manifest.update.protected'));
            $preserve = KraftUpdateFiles::preservation($manifest['update']['preserve'] ?? [], ['manifest.json']);
            File::ensureDirectoryExists($run.'/stage', 0700);
            if (!chmod($run, 0700) || !chmod($run.'/stage', 0700)) {
                throw new RuntimeException(__('system.extensions.update_private_storage'));
            }
            $files = [];
            foreach (File::allFiles($package['directory'], true) as $file) {
                $relative = str_replace('\\', '/', $file->getRelativePathname());
                if (KraftUpdateFiles::preserved($relative, true)) {
                    continue;
                }
                $path = $scope.'/'.$relative;
                KraftUpdateFiles::validate($path, $scope, [], true);
                $target = $run.'/stage/'.$path;
                File::ensureDirectoryExists(dirname($target), 0700);
                if (!copy($file->getPathname(), $target) || !chmod($target, 0600)) {
                    throw new RuntimeException(__('system.extensions.update_private_storage'));
                }
                $files[$path] = hash_file('sha256', $target);
            }
            $extension = [
                'id' => $manifest['id'],
                'name' => $manifest['name'],
                'type' => $manifest['type'],
                'path' => $scope,
                'operation' => $installation ? 'install' : 'update',
                'previous_version' => $installed['installed_version'] ?? $installed['version'],
                'previous_manifest_sha256' => hash_file('sha256', $installed['path'].'/manifest.json'),
                'was_enabled' => $installed['enabled'],
                'enable_after_install' => $installation && $manifest['type'] !== 'theme',
                'market_provenance' => $market === null
                        ? null
                        : app(MarketSecurity::class)->provenance(
                            $market,
                            hash_file('sha256', $package['directory'].'/manifest.json'),
                        ),
            ];
            app(UpdatePreparation::class)->publish(
                $id,
                ['version' => $manifest['version'], 'files' => $files, 'delete' => [], 'preserve' => $preserve],
                $extension,
                [$scope],
            );

            return $id;
        } catch (Throwable $error) {
            if ($run !== null && is_dir($run)) {
                File::deleteDirectory($run);
            }
            throw $error;
        } finally {
            File::deleteDirectory($package['cleanup']);
        }
    }
}
