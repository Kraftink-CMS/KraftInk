<?php

namespace App\Console\Commands;

use App\Kraft\Extensions\ExtensionManager;
use App\Kraft\Extensions\PackagePaths;
use App\Kraft\Extensions\PrivateLibraries;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

class ExtensionMigrateCommand extends Command
{
    protected $signature = 'kraft:extension-migrate {id} {version} {run}';

    protected $description = 'Run the migrations of an extension in its prepared operation';

    public function handle(ExtensionManager $manager): int
    {
        $run = $this->argument('run');
        if (
            !preg_match('/^[0-9]{14}-[a-f0-9]{12}$/D', $run) ||
            getenv('KRAFT_EXTENSION_MIGRATION_RUN') !== $run ||
            !filter_var(getenv('KRAFT_SAFE_MODE'), FILTER_VALIDATE_BOOLEAN)
        ) {
            throw new RuntimeException(__('system.extensions.update_boundary'));
        }
        $journal = storage_path('app/updates/'.$run.'/journal.json');
        for ($cursor = $journal; $cursor !== dirname($cursor); $cursor = dirname($cursor)) {
            if (is_link($cursor)) {
                throw new RuntimeException(__('system.extensions.update_boundary'));
            }
        }
        $state = json_decode(file_get_contents($journal), true, 64, JSON_THROW_ON_ERROR);
        $extension = $manager->all()[$this->argument('id')] ?? null;
        if (
            !$extension ||
            !$extension['valid'] ||
            ($extension['market_revoked'] ?? false) ||
            ($state['id'] ?? null) !== $run ||
            ($state['state'] ?? null) !== 'migrating' ||
            empty($state['database_snapshot']) ||
            !in_array($state['extension']['operation'] ?? null, ['install', 'update'], true) ||
            ($state['extension']['id'] ?? null) !== $extension['id'] ||
            ($state['manifest']['version'] ?? null) !== $this->argument('version') ||
            !version_compare($extension['version'], $this->argument('version'), '==')
        ) {
            throw new RuntimeException(__('system.extensions.update_boundary'));
        }
        $scope = app(PackagePaths::class)->scope($extension);
        $hash = $state['manifest']['files'][$scope.'/manifest.json'] ?? null;
        if (
            ($state['extension']['path'] ?? null) !== $scope ||
            !is_string($hash) ||
            !hash_equals($hash, hash_file('sha256', $extension['path'].'/manifest.json'))
        ) {
            throw new RuntimeException(__('system.extensions.update_boundary'));
        }
        $manager->assertInstallable($extension);
        $migrations = $extension['path'].'/database/migrations';
        if (!is_dir($migrations)) {
            return self::SUCCESS;
        }
        foreach ([$extension['path'].'/database', $migrations, ...glob($migrations.'/*.php') ?: []] as $path) {
            if (is_link($path)) {
                throw new RuntimeException(__('system.extensions.update_boundary'));
            }
        }
        $normalize = fn ($path) => PHP_OS_FAMILY === 'Windows'
            ? strtolower(str_replace('\\', '/', $path))
            : str_replace('\\', '/', $path);
        if ($normalize(realpath($migrations)) !== $normalize(realpath($extension['path']).'/database/migrations')) {
            throw new RuntimeException(__('system.extensions.update_boundary'));
        }
        app(PrivateLibraries::class)->load($extension);
        if (isset($extension['namespace'])) {
            $loader = require base_path('vendor/autoload.php');
            $loader->addPsr4($extension['namespace'], $extension['path'].'/src', true);
        }

        return Artisan::call('migrate', [
            '--path' => [realpath($migrations)],
            '--realpath' => true,
            '--force' => true,
            '--no-interaction' => true,
        ]);
    }
}
