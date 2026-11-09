<?php

namespace App\Kraft\Extensions;

use App\Events\AccountDeleting;
use App\Events\ExportingPersonalData;
use App\Kraft\Extensions\Contracts\PrivacyHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use Composer\Semver\Semver;
use RuntimeException;
use Throwable;

class ExtensionPrivacy
{
    public function export(ExportingPersonalData $event): void
    {
        foreach ($this->handlers() as $id => $handler) {
            try {
                $staged = new ExportingPersonalData($event->user);
                $handler->export($staged);
                if (array_diff(array_keys($staged->data), [$id]) !== []) {
                    throw new RuntimeException('Invalid extension export namespace.');
                }
                if (array_key_exists($id, $staged->data)) {
                    $event->data[$id] = $staged->data[$id];
                }
            } catch (Throwable $exception) {
                $this->fail($id, $exception);
            }
        }
    }

    public function anonymize(AccountDeleting $event): void
    {
        foreach ($this->handlers() as $id => $handler) {
            try {
                $handler->anonymize($event);
            } catch (Throwable $exception) {
                $this->fail($id, $exception);
            }
        }
    }

    private function handlers(): array
    {
        $handlers = [];
        $loader = require base_path('vendor/autoload.php');
        $extensions = app(ExtensionManager::class)->all();
        foreach (app(PackageRetirement::class)->all() as $id => $extension) {
            $extensions[$id] = $extension;
        }
        $available = [];
        foreach ($extensions as $extension) {
            $id = $extension['id'];
            try {
                if (array_key_exists('installed', $extension) && !$extension['installed']) {
                    continue;
                }
                if (!$extension['valid']) {
                    throw new RuntimeException('Invalid installed extension.');
                }
                if (array_key_exists('manifest_current', $extension) && !$extension['manifest_current']) {
                    throw new RuntimeException('Installed extension privacy schema must be repaired.');
                }
                if (config('kraft.safe_mode') || filter_var(getenv('KRAFT_SAFE_MODE'), FILTER_VALIDATE_BOOLEAN)) {
                    if (array_key_exists('privacy', $extension)) {
                        throw new RuntimeException('Extension privacy unavailable in safe mode.');
                    }

                    continue;
                }
                app(Manifest::class)->compatible($extension);
                if (isset($extension['namespace'])) {
                    $loader->addPsr4($extension['namespace'], $extension['path'].'/src', true);
                }
                if (is_dir($extension['path'].'/lang')) {
                    app('translator')->addNamespace($id, $extension['path'].'/lang');
                }
                $available[$id] = $extension;
            } catch (Throwable $exception) {
                $this->fail($id, $exception);
            }
        }
        $loaded = [];
        foreach ($available as $id => $extension) {
            if (!array_key_exists('privacy', $extension)) {
                continue;
            }
            try {
                $order = [];
                $visiting = [];
                $this->libraryOrder($id, $available, $order, $visiting);
                foreach ($order as $dependency => $package) {
                    if (!isset($loaded[$dependency])) {
                        app(PrivateLibraries::class)->load($package);
                        $loaded[$dependency] = true;
                    }
                }
                $path = app(Manifest::class)->privacyPath($extension, $extension['path']);
                $class = $extension['privacy'];
                if (
                    !is_subclass_of($class, PrivacyHandler::class) ||
                    realpath(new ReflectionClass($class)->getFileName()) !== $path
                ) {
                    throw new RuntimeException('Invalid extension privacy implementation.');
                }
                $handlers[$id] = app($class);
            } catch (Throwable $exception) {
                $this->fail($id, $exception);
            }
        }

        return $handlers;
    }

    private function libraryOrder(string $id, array $available, array &$order, array &$visiting): void
    {
        if (isset($order[$id])) {
            return;
        }
        if (isset($visiting[$id])) {
            throw new RuntimeException(__('system.extensions.dependency_cycle', ['id' => $id]));
        }
        $extension = $available[$id];
        if (isset($extension['libraries']) && !app(ExtensionManager::class)->migrationsApplied($extension)) {
            throw new RuntimeException(__('system.extensions.incomplete_installation'));
        }
        $visiting[$id] = true;
        $constraints = app(DependencyConstraints::class);
        foreach ($constraints->extensions($constraints->requirements($extension)) as $dependency => $requirement) {
            $target = $available[$dependency] ?? null;
            if (
                !$target ||
                $target['type'] !== $requirement['type'] ||
                !Semver::satisfies($target['version'], $requirement['constraint'])
            ) {
                throw new RuntimeException(
                    __('system.extensions.install_dependency', [
                        'id' => $dependency,
                        'constraint' => $requirement['constraint'],
                    ]),
                );
            }
            $this->libraryOrder($dependency, $available, $order, $visiting);
        }
        unset($visiting[$id]);
        $order[$id] = $extension;
    }

    private function fail(string $id, Throwable $exception): never
    {
        Log::warning('Extension privacy operation unavailable', ['extension' => $id, 'exception' => $exception::class]);
        app(ExtensionManager::class)->privacyFailure($id);
        throw ValidationException::withMessages([
            'account' => __('site.account.privacy.exports.extension_failed', ['id' => $id]),
        ]);
    }
}
