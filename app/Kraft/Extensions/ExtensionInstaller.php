<?php

namespace App\Kraft\Extensions;

use App\Kraft\Migration\ImportManager;
use App\Kraft\Updates\UpdatePreparation;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;
use App\Http\Middleware\OperationGuard;

class ExtensionInstaller
{
    public function stage(
        string $id,
        array $options = [],
        ?array $marketProvenance = null,
        ?string $replacementHash = null,
    ): string {
        return OperationGuard::staging(fn () => $this->prepare($id, $options, $marketProvenance, $replacementHash));
    }

    private function prepare(string $id, array $options, ?array $marketProvenance, ?string $replacementHash): string
    {
        if (!empty(app(InstallationReceipts::class)->all()[$id]['retired_uuid'])) {
            throw new RuntimeException(__('system.extensions.restore_before_install'));
        }
        if (app(ImportManager::class)->isRunning()) {
            throw new RuntimeException(__('system.extensions.update_busy'));
        }
        $manager = app(ExtensionManager::class);
        $extension = $manager->all()[$id] ?? throw new RuntimeException(__('system.extensions.missing'));
        if (app(BuiltinExtensions::class)->contains($id)) {
            throw new RuntimeException(__('system.extensions.builtin_managed'));
        }
        if (!$extension['valid'] || !in_array($extension['type'], ['plugin', 'theme', 'game'], true)) {
            throw new RuntimeException(__('system.extensions.invalid_manifest'));
        }
        if (
            array_diff(array_keys($options), ['enable_after_install', 'select_theme']) ||
            count(array_filter($options, fn ($value) => !is_bool($value))) ||
            (!empty($options['select_theme']) && $extension['type'] !== 'theme')
        ) {
            throw new RuntimeException(__('system.extensions.invalid_manifest'));
        }
        $enableAfterInstall =
            $options['enable_after_install'] ?? $extension['type'] === 'plugin' && !$extension['installed'];
        if ($replacementHash !== null) {
            if (
                !preg_match('/^[a-f0-9]{64}$/D', $replacementHash) ||
                !hash_equals($replacementHash, hash_file('sha256', $extension['path'].'/manifest.json'))
            ) {
                throw new RuntimeException(__('system.extensions.market_package_mismatch'));
            }
            $candidate = app(Manifest::class)->read($extension['path'], $extension['type'], $id);
            $manager->assertInstallable($candidate);
            $provenance = app(MarketSecurity::class)->validateProvenance(
                $marketProvenance,
                $candidate,
                $replacementHash,
            );
            if ($provenance !== null) {
                app(MarketSecurity::class)->assertPackageAllowed(
                    $provenance['market_id'],
                    ['id' => $provenance['resource_id'], 'type' => $provenance['type']],
                    $provenance['version'],
                );
            }
        } else {
            $manager->assertInstallable($extension);
        }
        $scope = app(PackagePaths::class)->scope($extension);
        $run = date('YmdHis').'-'.bin2hex(random_bytes(6));
        $root = storage_path('app/updates/'.$run);
        try {
            $stage = $root.'/stage/'.$scope;
            File::ensureDirectoryExists($stage, 0700);
            if (!chmod($root, 0700) || !chmod($root.'/stage', 0700)) {
                throw new RuntimeException(__('system.extensions.update_private_storage'));
            }
            $manifest = $extension['path'].'/manifest.json';
            if (!copy($manifest, $stage.'/manifest.json') || !chmod($stage.'/manifest.json', 0600)) {
                throw new RuntimeException(__('system.extensions.update_private_storage'));
            }
            $hash = hash_file('sha256', $stage.'/manifest.json');
            app(UpdatePreparation::class)->publish(
                $run,
                ['version' => $extension['version'], 'files' => [$scope.'/manifest.json' => $hash], 'delete' => []],
                [
                    'id' => $id,
                    'name' => $extension['name'],
                    'type' => $extension['type'],
                    'path' => $scope,
                    'operation' => 'install',
                    'previous_version' => $extension['version'],
                    'previous_manifest_sha256' => $hash,
                    'was_enabled' => false,
                    'enable_after_install' => $extension['type'] === 'game' || $enableAfterInstall,
                    'select_theme' => $options['select_theme'] ?? false,
                    'market_provenance' => $marketProvenance,
                ],
            );

            return $run;
        } catch (Throwable $exception) {
            if (is_dir($root)) {
                File::deleteDirectory($root);
            }
            throw $exception;
        }
    }
}
