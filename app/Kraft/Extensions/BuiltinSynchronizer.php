<?php

namespace App\Kraft\Extensions;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use KraftBuiltinPackages;

class BuiltinSynchronizer
{
    public function synchronize(?array $expected = null): void
    {
        require_once base_path('updater/builtins.php');
        $builtins = app(BuiltinExtensions::class);
        $inventory = KraftBuiltinPackages::inventory($builtins->inventory());
        if ($expected !== null && $inventory !== KraftBuiltinPackages::inventory($expected)) {
            throw new RuntimeException('The installed builtin inventory does not match the signed release.');
        }
        $manager = new ExtensionManager();
        $all = $manager->all();
        $receipts = app(InstallationReceipts::class);
        $installed = $receipts->all();
        $packages = [];
        foreach ($inventory as $type => $ids) {
            foreach ($ids as $id) {
                $extension = $all[$id] ?? null;
                if (!$extension || !$extension['valid'] || !$extension['builtin'] || $extension['type'] !== $type) {
                    throw new RuntimeException('A declared bundled extension is missing or invalid: '.$id);
                }
                $manager->assertInstallable($extension, $all);
                $packages[$id] = $extension;
            }
        }
        KraftBuiltinPackages::selfContained($packages);
        $ordered = [];
        $visiting = [];
        $visit = function (string $id) use (&$visit, &$ordered, &$visiting, $packages): void {
            if (isset($ordered[$id])) {
                return;
            }
            if (isset($visiting[$id])) {
                throw new RuntimeException(__('system.extensions.dependency_cycle', ['id' => $id]));
            }
            $visiting[$id] = true;
            $extension = $packages[$id];
            $dependencies = app(DependencyConstraints::class)->dependencies($extension);
            foreach (array_intersect(array_keys($dependencies), array_keys($packages)) as $dependency) {
                $visit($dependency);
            }
            unset($visiting[$id]);
            $ordered[$id] = $extension;
        };
        foreach (array_keys($packages) as $id) {
            $visit($id);
        }
        foreach ($ordered as $extension) {
            app(PrivateLibraries::class)->load($extension);
            $path = $extension['path'].'/database/migrations';
            if (
                is_dir($path) &&
                Artisan::call('migrate', [
                    '--path' => [$path],
                    '--realpath' => true,
                    '--force' => true,
                    '--no-interaction' => true,
                ]) !== 0
            ) {
                throw new RuntimeException('A bundled extension migration failed: '.$extension['id']);
            }
        }
        $manager = new ExtensionManager();
        foreach ($ordered as $id => $extension) {
            app(ActivationProbe::class)->verify($id);
            $manager->confirmInstallation($id, $extension['version']);
            $enabled =
                $extension['type'] === 'game' || (bool) ($installed[$id]['enabled'] ?? $extension['requested_enabled']);
            $receipts->setEnabled($id, $enabled);
        }
        foreach (array_keys($ordered) as $id) {
            app(ExtensionManager::class)->refreshVerified($id);
        }
    }
}
