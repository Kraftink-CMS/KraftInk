<?php

namespace App\Kraft\Extensions;

use Composer\Autoload\ClassLoader;
use App\Models\ExtensionInstallation;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Settings;
use Composer\Semver\Semver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use App\Events\ExtensionStateChanging;
use App\Http\Middleware\ExtensionConfiguration;
use App\Http\Middleware\OperationGuard;
use App\Kraft\Setup\Installation;
use App\Kraft\Updates\UpdateManager;
use Illuminate\Support\ServiceProvider;

class ExtensionManager
{
    public const array THEME_SETTINGS = ['site' => 'theme', 'admin' => 'admin_theme'];

    private array $errors = [];

    private array $incomplete = [];

    private ?array $appliedMigrations = null;

    public static function viewsPath(array $extension): string
    {
        return rtrim($extension['path'], '/\\').'/resources/views';
    }

    public function all(): array
    {
        $reader = app(Manifest::class);
        $receipts = app(InstallationReceipts::class)->all();
        $builtins = app(BuiltinExtensions::class);
        $marketSecurity = app(MarketSecurity::class);
        $normalize = fn ($path) => PHP_OS_FAMILY === 'Windows'
            ? strtolower(str_replace('\\', '/', $path ?: ''))
            : str_replace('\\', '/', $path ?: '');
        $extensions = [];
        foreach ($this->roots() as [$type, $root]) {
            foreach (glob($root.'/*/manifest.json') ?: [] as $path) {
                try {
                    $directory = dirname($path);
                    if (
                        !str_starts_with(
                            str_replace('\\', '/', realpath($directory)).'/',
                            rtrim(str_replace('\\', '/', realpath($root)), '/').'/',
                        )
                    ) {
                        throw new RuntimeException(__('system.extensions.archive_unsafe'));
                    }
                    $manifest = $reader->read($directory, $type, basename($directory));
                    $receipt = $receipts[$manifest['id']] ?? null;
                    $builtin =
                        $builtins->contains($manifest['id'], $type) &&
                        $normalize(realpath($directory)) === $normalize($builtins->path($manifest['id']));
                    if (isset($extensions[$manifest['id']]) || ($builtins->contains($manifest['id']) && !$builtin)) {
                        throw new RuntimeException(__('system.extensions.duplicate_id'));
                    }
                    if (!$builtin && !empty($receipt['retired_uuid'])) {
                        continue;
                    }
                    $installed = $receipt !== null || $builtin;
                    $current =
                        $builtin ||
                        ($receipt !== null &&
                            $receipt['type'] === $type &&
                            version_compare($receipt['version'], $manifest['version'], '==') &&
                            hash_equals($receipt['manifest_hash'], hash_file('sha256', $path)));
                    $requested =
                        $type === 'game'
                            ? $installed
                            : ($receipt
                                ? (bool) $receipt['enabled']
                                : $builtin &&
                                    ($type !== 'theme' ||
                                        $this->selectedTheme($manifest['target']) === $manifest['id']));
                    $revoked =
                        !$builtin &&
                        $marketSecurity->revoked($manifest + ['path' => $directory, 'builtin' => $builtin]);
                    $gameCompatible = !GameSupport::declarations($manifest) ||
                        GameSupport::matches($manifest, app(GameRegistry::class)->activeId());
                    $active =
                        $installed &&
                        $current &&
                        $requested &&
                        $gameCompatible &&
                        ($type !== 'theme' || $this->selectedTheme($manifest['target']) === $manifest['id']) &&
                        !isset($this->incomplete[$manifest['id']]) &&
                        !isset($this->errors[$manifest['id']]) &&
                        !$revoked;
                    $quarantined =
                        ($receipt && !$current) ||
                        isset($this->incomplete[$manifest['id']]) ||
                        isset($this->errors[$manifest['id']]) ||
                        $revoked;
                    $extensions[$manifest['id']] = array_merge(
                        $manifest,
                        [
                            'path' => $directory,
                            'builtin' => $builtin,
                            'valid' => true,
                            'installed' => $installed,
                            'installed_version' => $builtin ? $manifest['version'] : $receipt['version'] ?? null,
                            'manifest_current' => $current,
                            'requested_enabled' => $requested,
                            'game_compatible' => $gameCompatible,
                            'enabled' => $active,
                            'installation_state' => $quarantined
                                ? ExtensionState::Suspended->value
                                : ($active
                                    ? ExtensionState::Active->value
                                    : ($installed
                                        ? ExtensionState::Installed->value
                                        : ExtensionState::Present->value)),
                        ],
                        $revoked ? ['error' => __('market.security.revoked'), 'market_revoked' => true] : [],
                    );
                } catch (Throwable $error) {
                    $id = 'invalid-'.substr(hash('sha256', $path), 0, 12);
                    $receipt = $receipts[basename(dirname($path))] ?? null;
                    $builtin =
                        $builtins->contains(basename(dirname($path)), $type) &&
                        $normalize(realpath(dirname($path))) === $normalize($builtins->path(basename(dirname($path))));
                    $extensions[$id] = [
                        'id' => $id,
                        'type' => $type,
                        'name' => basename(dirname($path)),
                        'version' => '?',
                        'path' => dirname($path),
                        'builtin' => $builtin,
                        'enabled' => false,
                        'installed' => $receipt !== null || $builtin,
                        'installation_state' => $receipt || $builtin ? 'quarantined' : 'present',
                        'valid' => false,
                        'error' => $error->getMessage(),
                    ];
                }
            }
        }
        foreach ($receipts as $id => $receipt) {
            if (!$builtins->contains($id, $receipt['type']) && !empty($receipt['retired_uuid'])) {
                continue;
            }
            if (
                !isset($extensions[$id]) &&
                !collect($extensions)->contains(
                    fn ($extension) => $extension['path'] && basename($extension['path']) === $id,
                )
            ) {
                $extensions[$id] = [
                    'id' => $id,
                    'type' => $receipt['type'],
                    'name' => $id,
                    'version' => $receipt['version'],
                    'installed_version' => $receipt['version'],
                    'path' => null,
                    'builtin' => $builtins->contains($id, $receipt['type']),
                    'enabled' => false,
                    'installed' => true,
                    'installation_state' => 'quarantined',
                    'valid' => false,
                    'error' => __('system.extensions.package_missing'),
                ];
            }
        }
        $ids = array_keys(
            array_filter($extensions, fn ($extension) => $extension['valid'] && isset($extension['namespace'])),
        );
        $collisions = [];
        foreach ($ids as $index => $first) {
            foreach (array_slice($ids, $index + 1) as $second) {
                if (!$reader->namespacesOverlap($extensions[$first]['namespace'], $extensions[$second]['namespace'])) {
                    continue;
                }
                $firstTrusted = $extensions[$first]['installed'] && $extensions[$first]['manifest_current'];
                $secondTrusted = $extensions[$second]['installed'] && $extensions[$second]['manifest_current'];
                if ($extensions[$first]['builtin'] && !$extensions[$second]['builtin']) {
                    $collisions[$second] = true;
                } elseif ($extensions[$second]['builtin'] && !$extensions[$first]['builtin']) {
                    $collisions[$first] = true;
                } elseif ($firstTrusted && !$secondTrusted) {
                    $collisions[$second] = true;
                } elseif ($secondTrusted && !$firstTrusted) {
                    $collisions[$first] = true;
                } else {
                    $collisions[$first] = $collisions[$second] = true;
                }
            }
        }
        foreach (array_keys($collisions) as $id) {
            $extensions[$id]['valid'] = false;
            $extensions[$id]['enabled'] = false;
            $extensions[$id]['installation_state'] = $extensions[$id]['installed'] ? 'quarantined' : 'present';
            $extensions[$id]['error'] = __('system.extensions.invalid_provider');
        }

        return $extensions;
    }

    public function enabled(): array
    {
        return array_keys(array_filter($this->all(), fn ($extension) => $extension['enabled']));
    }

    public function contributors(): array
    {
        $extensions = $this->all();
        $diagnostics = $this->diagnostics($extensions);

        return array_filter(
            $extensions,
            fn (array $extension, string $id) => ($extension['valid'] ?? false) &&
                ($extension['enabled'] ?? false) &&
                ($extension['installation_state'] ?? null) !== 'quarantined' &&
                !isset($diagnostics[$id]),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    public function runtimeContributors(): array
    {
        return config('kraft.safe_mode') ? [] : $this->contributors();
    }

    public function diagnostics(?array $extensions = null): array
    {
        $errors = $this->errors;
        foreach ($extensions ?? $this->all() as $extension) {
            if (($extension['installation_state'] ?? null) === 'quarantined') {
                $errors[$extension['id']] ??= $extension['error'] ?? __('system.extensions.incomplete_installation');
            }
        }

        return $errors;
    }

    public function privacyFailure(string $id): void
    {
        $this->errors['privacy.'.$id] = __('site.account.privacy.exports.extension_failed', ['id' => $id]);
    }

    /**
     * @throws Throwable
     */
    public function boot(): void
    {
        $this->appliedMigrations = null;
        $probe = getenv('KRAFT_PROBE_EXTENSION') ?: null;
        if ($probe && ($paths = getenv('KRAFT_PROBE_PATHS'))) {
            $paths = json_decode($paths, true, 16, JSON_THROW_ON_ERROR);
            config([
                'kraft.extensions' => $paths['extensions'],
            ]);
            app()->useStoragePath($paths['storage']);
        }
        if (!$probe && (config('kraft.safe_mode') || filter_var(getenv('KRAFT_SAFE_MODE'), FILTER_VALIDATE_BOOLEAN))) {
            return;
        }
        /** @var ClassLoader $loader */
        $loader = require base_path('vendor/autoload.php');
        foreach (self::THEME_SETTINGS as $target => $key) {
            config(['kraft.'.$key => $this->selectedTheme($target)]);
        }
        $extensions = $this->all();
        if (!$probe && !app(Installation::class)->installed() && !app()->runningUnitTests()) {
            foreach ($extensions as &$extension) {
                if (!$extension['builtin']) {
                    $extension['enabled'] = false;
                }
            }
            unset($extension);
        }
        if ($probe) {
            if (!isset($extensions[$probe]) || !$extensions[$probe]['valid']) {
                throw new RuntimeException(__('system.extensions.invalid_manifest'));
            }
            if ($extensions[$probe]['market_revoked'] ?? false) {
                throw new RuntimeException(__('market.security.revoked'));
            }
            if ($extensions[$probe]['type'] === 'theme') {
                foreach ($extensions as &$extension) {
                    if ($extension['type'] === 'theme' && $extension['target'] === $extensions[$probe]['target']) {
                        $extension['enabled'] = $extension['id'] === $probe;
                    }
                }
                unset($extension);
                config(['kraft.'.self::THEME_SETTINGS[$extensions[$probe]['target']] => $probe]);
            }
            $extensions[$probe]['enabled'] = true;
            $visiting = [];
            $this->probeDependencies($probe, $extensions, $visiting);
        }
        foreach ($extensions as &$extension) {
            $loadable =
                $extension['valid'] &&
                (($extension['installed'] &&
                    $extension['manifest_current'] &&
                    $extension['installation_state'] !== 'quarantined') ||
                    $probe === $extension['id']);
            if ($loadable) {
                try {
                    app(Manifest::class)->compatible($extension);
                } catch (Throwable $error) {
                    if ($probe === $extension['id']) {
                        throw $error;
                    }
                    $this->errors[$extension['id']] = $error->getMessage();
                    $extension['enabled'] = false;
                    $loadable = false;
                }
            }
            if ($loadable && $probe !== $extension['id'] && !$this->migrationsApplied($extension)) {
                $this->incomplete[$extension['id']] = true;
                $loadable = false;
            }
            if ($loadable && isset($extension['namespace'])) {
                $loader->addPsr4($extension['namespace'], $extension['path'].'/src', true);
            }
            if ($loadable && is_dir($extension['path'].'/lang')) {
                app('translator')->addNamespace($extension['id'], $extension['path'].'/lang');
            }
        }
        unset($extension);
        try {
            $ordered = $this->ordered($extensions);
        } catch (Throwable $error) {
            if ($probe) {
                throw $error;
            }
            $this->errors['dependencies'] = $error->getMessage();
            Log::warning('Extension dependency graph rejected', ['error' => $error->getMessage()]);

            return;
        }
        foreach ($ordered as $extension) {
            try {
                if (is_dir($extension['path'].'/database/migrations')) {
                    if ($probe !== $extension['id'] && !$this->migrationsApplied($extension)) {
                        $this->incomplete[$extension['id']] = true;
                        throw new RuntimeException(__('system.extensions.incomplete_installation'));
                    }
                    if (isset($this->incomplete[$extension['id']])) {
                        unset($this->incomplete[$extension['id']], $this->errors[$extension['id']]);
                    }
                }
                $this->assertDependencies($extension, $extensions);
                foreach ($this->dependencies($extension) as $dependency => $constraint) {
                    if (isset($this->errors[$dependency])) {
                        throw new RuntimeException(__('system.extensions.dependency_failed', ['id' => $dependency]));
                    }
                }
                app(PrivateLibraries::class)->load($extension);
                if (is_dir($extension['path'].'/database/migrations')) {
                    app('migrator')->path($extension['path'].'/database/migrations');
                }
                if (isset($extension['provider'], $extension['namespace'])) {
                    $loader->addPsr4($extension['namespace'], $extension['path'].'/src');
                    if (!is_subclass_of($extension['provider'], ServiceProvider::class)) {
                        throw new RuntimeException(__('system.extensions.invalid_provider'));
                    }
                    /** @var class-string<ServiceProvider> $provider */
                    $provider = $extension['provider'];
                    app()->register($provider);
                }
                if ($extension['type'] !== 'theme' && is_dir($overrides = self::viewsPath($extension).'/overrides')) {
                    view()->getFinder()->prependLocation($overrides);
                }
                if ($extension['type'] !== 'theme' && is_dir($extension['path'].'/lang/overrides')) {
                    app('translator')->addPath($extension['path'].'/lang/overrides');
                }
            } catch (Throwable $error) {
                if ($probe) {
                    throw $error;
                }
                $this->errors[$extension['id']] = $error->getMessage();
                Log::warning('Extension could not be loaded', [
                    'extension' => $extension['id'],
                    'error' => $error->getMessage(),
                ]);
            }
        }
        foreach ($extensions as $extension) {
            if (
                $extension['type'] !== 'theme' ||
                empty($extension['configuration']['routes']) ||
                (!$this->configurationAvailable($extension) && $probe !== $extension['id'])
            ) {
                continue;
            }
            try {
                $path = app(Manifest::class)->configurationPath($extension, $extension['path']);
                view()->addNamespace($extension['id'], self::viewsPath($extension));
                if (!app()->routesAreCached()) {
                    $middleware = [
                        'web',
                        'auth',
                        'permission:admin.access',
                        ExtensionConfiguration::class.':'.$extension['id'],
                    ];
                    if (!empty($extension['configuration']['permission'])) {
                        $middleware[] = 'permission:'.$extension['configuration']['permission'];
                    }
                    Route::middleware($middleware)->group($path);
                }
            } catch (Throwable $error) {
                if ($probe) {
                    throw $error;
                }
                $this->errors[$extension['id']] = $error->getMessage();
                Log::warning('Theme configuration could not be loaded', [
                    'extension' => $extension['id'],
                    'error' => $error->getMessage(),
                ]);
            }
        }
    }

    private function roots(): array
    {
        return [
            ['game', config('kraft.extensions.bundled.games')],
            ['plugin', config('kraft.extensions.bundled.plugins')],
            ['theme', config('kraft.extensions.bundled.themes')],
            ['game', config('kraft.extensions.installed.games')],
            ['plugin', config('kraft.extensions.installed.plugins')],
            ['theme', config('kraft.extensions.installed.themes')],
        ];
    }

    private function probeDependencies(string $id, array &$extensions, array &$visiting): void
    {
        if (isset($visiting[$id])) {
            return;
        }
        $visiting[$id] = true;
        foreach ($this->dependencies($extensions[$id]) as $dependency => $constraint) {
            $required = $extensions[$dependency] ?? null;
            if (
                !$required ||
                !$required['valid'] ||
                !$required['installed'] ||
                !$required['manifest_current'] ||
                ($required['market_revoked'] ?? false) ||
                !Semver::satisfies($required['installed_version'], $constraint)
            ) {
                throw new RuntimeException(
                    __('system.extensions.install_dependency', ['id' => $dependency, 'constraint' => $constraint]),
                );
            }
            $extensions[$dependency]['enabled'] = true;
            $this->probeDependencies($dependency, $extensions, $visiting);
        }
    }

    public function requiresRepair(string $id): bool
    {
        return isset($this->incomplete[$id]) || ($this->all()[$id]['installation_state'] ?? null) === 'quarantined';
    }

    public function refreshVerified(string $id): void
    {
        $this->appliedMigrations = null;
        unset($this->incomplete[$id], $this->errors[$id]);
    }

    public function migrationsApplied(array $extension): bool
    {
        $files = glob($extension['path'].'/database/migrations/*.php') ?: [];
        if ($files === []) {
            return true;
        }
        if ($this->appliedMigrations === null) {
            try {
                $table = config('database.migrations.table', 'migrations');
                $this->appliedMigrations = DB::getSchemaBuilder()->hasTable($table)
                    ? array_fill_keys(DB::table($table)->pluck('migration')->all(), true)
                    : [];
            } catch (Throwable) {
                $this->appliedMigrations = [];
            }
        }

        return array_all($files, fn ($file) => isset($this->appliedMigrations[basename($file, '.php')]));
    }

    private function dependencies(array $extension): array
    {
        return app(DependencyConstraints::class)->dependencies($extension);
    }

    private function ordered(array $extensions): array
    {
        $errors = app(ExtensionRequirements::class)->cycles($extensions);
        if ($errors) {
            throw new RuntimeException(implode(PHP_EOL, $errors));
        }
        $ordered = [];
        $visiting = [];
        $visited = [];
        $visit = function (string $id) use (&$visit, &$ordered, &$visiting, &$visited, $extensions): void {
            if (isset($visited[$id])) {
                return;
            }
            if (isset($visiting[$id])) {
                throw new RuntimeException(__('system.extensions.dependency_cycle', ['id' => $id]));
            }
            $visiting[$id] = true;
            foreach ($this->dependencies($extensions[$id]) as $dependency => $constraint) {
                if (
                    isset($extensions[$dependency]) &&
                    $extensions[$dependency]['enabled'] &&
                    $extensions[$dependency]['valid']
                ) {
                    $visit($dependency);
                }
            }
            unset($visiting[$id]);
            $visited[$id] = true;
            $ordered[] = $extensions[$id];
        };
        foreach ($extensions as $id => $extension) {
            if ($extension['enabled'] && $extension['valid']) {
                $visit($id);
            }
        }

        return $ordered;
    }

    public function adoptVerified(string $id, bool $enabled = false): void
    {
        $this->appliedMigrations = null;
        $extension = $this->all()[$id] ?? throw new RuntimeException(__('system.extensions.missing'));
        if (!$extension['valid'] || !$this->migrationsApplied($extension)) {
            throw new RuntimeException(__('system.extensions.incomplete_installation'));
        }
        $this->assertDependencies($extension, $this->all());
        app(ActivationProbe::class)->verify($id);
        app(InstallationReceipts::class)->record($extension, $enabled);
        unset($this->incomplete[$id], $this->errors[$id]);
    }

    public function confirmInstallation(string $id, string $expectedVersion, ?array $marketProvenance = null): void
    {
        $this->appliedMigrations = null;
        $extension = $this->all()[$id] ?? throw new RuntimeException(__('system.extensions.missing'));
        if (
            !$extension['valid'] ||
            !version_compare($extension['version'], $expectedVersion, '==') ||
            !$this->migrationsApplied($extension)
        ) {
            throw new RuntimeException(__('system.extensions.incomplete_installation'));
        }
        app(Manifest::class)->compatible($extension);
        app(InstallationReceipts::class)->record($extension, null, $marketProvenance);
        unset($this->incomplete[$id], $this->errors[$id]);
    }

    public function assertThemeSelection(?string $id, string $target = 'site'): void
    {
        $this->themeSetting($target);
        $extensions = $this->all();
        if (
            $id !== null &&
            (!isset($extensions[$id]) || !$extensions[$id]['valid'] || $extensions[$id]['type'] !== 'theme')
        ) {
            throw ValidationException::withMessages(['theme' => __('system.extensions.invalid_manifest')]);
        }
        if ($id !== null && (!$extensions[$id]['installed'] || !$extensions[$id]['manifest_current'])) {
            throw ValidationException::withMessages(['theme' => __('system.extensions.install_first')]);
        }
        if ($id !== null && ($extensions[$id]['market_revoked'] ?? false)) {
            throw ValidationException::withMessages(['theme' => __('market.security.revoked')]);
        }
        if ($id !== null && $extensions[$id]['target'] !== $target) {
            throw ValidationException::withMessages(['theme' => __('system.extensions.theme_target_mismatch')]);
        }
        foreach ($extensions as &$extension) {
            if ($extension['valid'] && $extension['type'] === 'theme' && $extension['target'] === $target) {
                $extension['enabled'] = $extension['id'] === $id;
            }
        }
        unset($extension);
        $requirements = app(ExtensionRequirements::class);
        $errors = [];
        foreach ($extensions as $extension) {
            if ($extension['enabled'] && $extension['valid']) {
                $errors = array_merge($errors, $requirements->errors($extension, $extensions, true));
            }
        }
        $errors = array_values(array_unique(array_merge($errors, $requirements->cycles($extensions))));
        if ($errors) {
            throw ValidationException::withMessages(['theme' => $errors]);
        }
    }

    public function toggle(string $id, bool $enable): void
    {
        File::ensureDirectoryExists(storage_path('app'));
        $lock = fopen(storage_path('app/extensions.lock'), 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) {
            throw new RuntimeException(__('system.extensions.lock_failed'));
        }
        try {
            $all = $this->all();
            $extension = $all[$id] ?? throw new RuntimeException(__('system.extensions.missing'));
            if ($extension['type'] === 'game') {
                throw ValidationException::withMessages(['extension' => __('system.extensions.builtin_game_managed')]);
            }
            if (!$extension['valid'] || !$extension['installed']) {
                throw ValidationException::withMessages(['extension' => __('system.extensions.install_first')]);
            }
            if ($extension['type'] === 'theme' && $enable) {
                $this->selectTheme($id, $extension['target']);

                return;
            }
            if ($enable) {
                $this->assertCanActivate($id);
                event(new ExtensionStateChanging($extension, true));
                app(ActivationProbe::class)->verify($id);
                unset($this->incomplete[$id], $this->errors[$id]);
            } else {
                $errors = app(ExtensionRequirements::class)->dependentErrors($id, $all);
                if ($extension['type'] === 'theme' && in_array($id, $this->selectedThemes(), true)) {
                    $errors[] = __('system.extensions.messages.active_theme');
                }
                if ($errors) {
                    throw ValidationException::withMessages(['extension' => $errors]);
                }
                event(new ExtensionStateChanging($extension, false));
            }
            $receipts = app(InstallationReceipts::class);
            if (($extension['builtin'] ?? false) && !isset($receipts->all()[$id])) {
                $receipts->record($extension, $enable);
            } else {
                $receipts->setEnabled($id, $enable);
            }
            Artisan::call('route:clear');
            Artisan::call('view:clear');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function selectTheme(?string $id, string $target = 'site'): void
    {
        $this->assertThemeSelection($id, $target);
        if ($id !== null) {
            app(ActivationProbe::class)->verify($id);
        }
        $themes = array_filter(
            $this->all(),
            fn ($extension) => $extension['valid'] && $extension['type'] === 'theme' && $extension['target'] === $target,
        );
        $ids = array_keys($themes);
        $previous = $this->selectedTheme($target);
        if ($previous && !in_array($previous, array_diff_key($this->selectedThemes(), [$target => true]), true)) {
            $ids[] = $previous;
        }
        DB::transaction(function () use ($id, $target, $ids, $themes) {
            $receipts = app(InstallationReceipts::class);
            if ($id !== null && ($themes[$id]['builtin'] ?? false) && !isset($receipts->all()[$id])) {
                $receipts->record($themes[$id], false);
            }
            ExtensionInstallation::query()
                ->where('type', 'theme')
                ->whereIn('id', $ids)
                ->update(['enabled' => false, 'updated_at' => now()]);
            if ($id !== null) {
                $changed = ExtensionInstallation::query()
                    ->where('id', $id)
                    ->where('type', 'theme')
                    ->whereNull('retired_uuid')
                    ->update(['enabled' => true, 'updated_at' => now()]);
                if (!$changed) {
                    throw ValidationException::withMessages(['theme' => __('system.extensions.install_first')]);
                }
            }
            app(Settings::class)->set($this->themeSetting($target), $id);
        });
        app(InstallationReceipts::class)->refresh();
        config(['kraft.'.$this->themeSetting($target) => $id]);
        Artisan::call('route:clear');
        Artisan::call('view:clear');
        view()->getFinder()->flush();
    }

    public function install(string $id, array $options = []): void
    {
        $run = OperationGuard::staging(function () use ($id, $options) {
            $extension = $this->all()[$id] ?? throw new RuntimeException(__('system.extensions.missing'));
            if (
                $options === [] &&
                $extension['valid'] &&
                $extension['installed'] &&
                $extension['manifest_current'] &&
                $this->migrationsApplied($extension)
            ) {
                return null;
            }

            return app(ExtensionInstaller::class)->stage($id, $options);
        });
        if ($run === null) {
            return;
        }
        $result = app(UpdateManager::class)->run($run);
        app(InstallationReceipts::class)->refresh();
        $this->appliedMigrations = null;
        $healthy = OperationGuard::staging(function () use ($id) {
            $installed = $this->all()[$id] ?? null;

            return $installed &&
                $installed['installed'] &&
                $installed['manifest_current'] &&
                $this->migrationsApplied($installed);
        });
        if (!$result->isSuccessful() || !$healthy) {
            throw new RuntimeException(__('system.extensions.install_failed'));
        }
    }

    public function assertInstallable(array $extension, ?array $all = null): void
    {
        if (($extension['market_revoked'] ?? false) || app(MarketSecurity::class)->revoked($extension)) {
            throw ValidationException::withMessages(['extension' => __('market.security.revoked')]);
        }
        $errors = app(ExtensionRequirements::class)->errors($extension, $all ?? $this->all(), false);
        if ($errors) {
            throw ValidationException::withMessages(['extension' => $errors]);
        }
    }

    public function assertMarketRequirements(array $resource, array $version): void
    {
        $this->assertInstallable([
            'id' => $resource['slug'],
            'type' => $resource['type'],
            'version' => $version['version'],
            'requires' => $version['requirements'],
        ] + GameSupport::declarations($version));
    }

    public function assertReplacement(array $manifest, ?array $all = null): void
    {
        if (app(BuiltinExtensions::class)->contains($manifest['id'])) {
            throw ValidationException::withMessages(['extension' => __('system.extensions.builtin_managed')]);
        }
        $all ??= $this->all();
        $installed = $all[$manifest['id']] ?? throw new RuntimeException(__('system.extensions.missing'));
        if (!$installed['valid'] || $installed['type'] !== $manifest['type']) {
            throw new RuntimeException(__('system.extensions.market_package_mismatch'));
        }
        if ($manifest['type'] === 'theme' && $manifest['target'] !== $installed['target']) {
            throw new RuntimeException(__('system.extensions.theme_target_changed'));
        }
        $requirements = app(ExtensionRequirements::class);
        $errors = $requirements->errors($manifest, $all, false);
        $all[$manifest['id']] = array_merge($manifest, [
            'name' => $manifest['name'] ?? $installed['name'],
            'enabled' => $installed['enabled'],
            'valid' => true,
            'path' => $installed['path'],
        ]);
        foreach ($all as $extension) {
            if ($extension['valid'] && $extension['enabled']) {
                $errors = array_merge($errors, $requirements->errors($extension, $all, true));
            }
        }
        $errors = array_values(array_unique(array_merge($errors, $requirements->cycles($all))));
        if ($errors) {
            throw ValidationException::withMessages(['extension' => $errors]);
        }
    }

    public function assertCanActivate(string $id): void
    {
        $all = $this->all();
        $extension = $all[$id] ?? throw new RuntimeException(__('system.extensions.missing'));
        if (!$extension['valid']) {
            throw ValidationException::withMessages(['extension' => __('system.extensions.invalid_manifest')]);
        }
        if ($extension['market_revoked'] ?? false) {
            throw ValidationException::withMessages(['extension' => __('market.security.revoked')]);
        }
        if ($extension['type'] === 'theme') {
            $this->assertThemeSelection($id, $extension['target']);

            return;
        }
        $errors = [];
        if (!$extension['installed'] || !$extension['manifest_current']) {
            $errors[] = __('system.extensions.install_first');
        }
        if (!$this->migrationsApplied($extension)) {
            $errors[] = __('system.extensions.incomplete_installation');
        }
        $all[$id]['enabled'] = true;
        $requirements = app(ExtensionRequirements::class);
        $errors = array_values(
            array_unique(
                array_merge($errors, $requirements->errors($extension, $all, true), $requirements->cycles($all)),
            ),
        );
        if ($errors) {
            throw ValidationException::withMessages(['extension' => $errors]);
        }
    }

    private function assertDependencies(array $extension, array $all): void
    {
        $errors = app(ExtensionRequirements::class)->errors($extension, $all, true);
        if ($errors) {
            throw ValidationException::withMessages(['extension' => $errors]);
        }
    }

    public function integrationReport(string $id): array
    {
        $all = $this->all();
        $report = [];
        $constraints = app(DependencyConstraints::class);
        foreach (
            $constraints->extensions($constraints->map($all[$id]['integrations'] ?? [])) as $targetId => $dependency
        ) {
            $constraint = $dependency['constraint'];
            $target = $all[$targetId] ?? null;
            $report[$targetId] = [
                'constraint' => $constraint,
                'installed' => (bool) ($target['installed'] ?? false),
                'active' => $target['enabled'] ?? false,
                'compatible' => $target && $target['valid']
                        ? $target['type'] === $dependency['type'] && Semver::satisfies($target['version'], $constraint)
                        : null,
            ];
        }

        return $report;
    }

    public function configurationRoute(array $extension): ?string
    {
        if (!$this->configurationAvailable($extension)) {
            return null;
        }
        if (
            $extension['type'] === 'theme' &&
            (!empty($extension['configuration']['schema']) || !empty($extension['templates']))
        ) {
            return Route::has('admin.themes.configuration.index') ? 'admin.themes.configuration.index' : null;
        }
        $route = $extension['configuration']['route'] ?? null;

        return $route && Route::has($route) ? $route : null;
    }

    public function configurationAvailable(array $extension): bool
    {
        if (
            config('kraft.safe_mode') ||
            filter_var(getenv('KRAFT_SAFE_MODE'), FILTER_VALIDATE_BOOLEAN) ||
            (!app(Installation::class)->installed() && !app()->runningUnitTests()) ||
            !$extension['valid'] ||
            !$extension['installed'] ||
            !$extension['manifest_current'] ||
            $extension['installation_state'] === 'quarantined' ||
            isset($this->errors[$extension['id']]) ||
            isset($this->incomplete[$extension['id']]) ||
            (!$extension['enabled'] &&
                ($extension['type'] !== 'theme' ||
                    (empty($extension['configuration']['routes']) &&
                        empty($extension['configuration']['schema']) &&
                        empty($extension['templates']))))
        ) {
            return false;
        }
        try {
            app(Manifest::class)->compatible($extension);
            if ($extension['type'] === 'theme' && !$extension['enabled']) {
                $extensions = $this->all();
                foreach ($this->dependencies($extension) as $id => $constraint) {
                    $dependency = $extensions[$id] ?? null;
                    if (
                        !$dependency ||
                        !$dependency['valid'] ||
                        !$dependency['installed'] ||
                        !$dependency['manifest_current'] ||
                        $dependency['installation_state'] === 'quarantined' ||
                        !Semver::satisfies($dependency['installed_version'], $constraint) ||
                        !$this->migrationsApplied($dependency)
                    ) {
                        return false;
                    }
                    app(Manifest::class)->compatible($dependency);
                }
            }

            return $this->migrationsApplied($extension);
        } catch (Throwable) {
            return false;
        }
    }

    public function navigation(string $area): array
    {
        $links = [];
        foreach ($this->contributors() as $extension) {
            if ($extension['type'] === 'theme' && $area !== 'admin' && $extension['target'] !== 'site') {
                continue;
            }
            foreach ($extension['navigation'][$area] ?? [] as $link) {
                if (isset($link['label'], $link['route']) && Route::has($link['route'])) {
                    $links[] = $link;
                }
            }
        }

        return $links;
    }

    public function permissions(): array
    {
        $permissions = [];
        foreach ($this->permissionGroups() as $group) {
            $permissions = array_merge($permissions, $group['permissions']);
        }

        return array_values(array_unique($permissions));
    }

    public function permissionDefinitions(): array
    {
        $definitions = [];
        foreach ($this->contributors() as $extension) {
            foreach ($extension['permissions'] ?? [] as $permission => $definition) {
                $definitions[$permission] ??= [
                    'label' => is_array($definition) ? $definition['label'] : $definition,
                    'default' => is_array($definition) ? $definition['default'] ?? null : null,
                ];
            }
        }

        return $definitions;
    }

    public function permissionGroups(): array
    {
        $groups = [];
        foreach ($this->contributors() as $extension) {
            $permissions = array_keys($extension['permissions'] ?? []);
            if (!$permissions) {
                continue;
            }
            $label = $extension['name'];
            foreach ($extension['navigation']['admin'] ?? [] as $link) {
                if (isset($link['label']) && (($link['id'] ?? null) === $extension['id'] || !empty($link['children']))) {
                    $label = $link['label'];

                    break;
                }
            }
            $groups[] = [
                'id' => $extension['id'],
                'name' => $extension['name'],
                'label' => $label,
                'permissions' => array_values(array_unique($permissions)),
            ];
        }

        return $groups;
    }

    private function themeSetting(string $target): string
    {
        return self::THEME_SETTINGS[$target] ??
            throw ValidationException::withMessages(['target' => __('system.extensions.invalid_manifest')]);
    }

    public function selectedTheme(string $target = 'site'): ?string
    {
        $key = $this->themeSetting($target);
        $probe = getenv('KRAFT_PROBE_EXTENSION');
        if ($probe && config('kraft.'.$key) === $probe) {
            return $probe;
        }
        $settings = app(Settings::class)->all();

        return array_key_exists($key, $settings) ? $settings[$key] : config('kraft.'.$key);
    }

    public function selectedThemes(): array
    {
        $themes = [];
        foreach (self::THEME_SETTINGS as $target => $key) {
            $themes[$target] = $this->selectedTheme($target);
        }

        return $themes;
    }

    public function activeTheme(string $target = 'site'): ?array
    {
        if (
            config('kraft.safe_mode') ||
            filter_var(getenv('KRAFT_SAFE_MODE'), FILTER_VALIDATE_BOOLEAN) ||
            (!getenv('KRAFT_PROBE_EXTENSION') && !app(Installation::class)->installed() && !app()->runningUnitTests())
        ) {
            return null;
        }
        $id = $this->selectedTheme($target);
        if (!$id) {
            return null;
        }
        $theme = $this->all()[$id] ?? null;
        if (
            $theme &&
            $theme['valid'] &&
            $theme['installed'] &&
            $theme['enabled'] &&
            $theme['type'] === 'theme' &&
            $theme['target'] === $target &&
            !isset($this->errors[$theme['id']])
        ) {
            try {
                app(Manifest::class)->compatible($theme);

                return $theme;
            } catch (Throwable $error) {
                $this->errors[$theme['id']] = $error->getMessage();
            }
        }

        return null;
    }
}
