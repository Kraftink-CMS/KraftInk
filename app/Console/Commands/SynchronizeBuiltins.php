<?php

namespace App\Console\Commands;

use App\Kraft\Extensions\BuiltinSynchronizer;
use App\Kraft\System\DatabaseSnapshots;
use Illuminate\Console\Command;
use RuntimeException;
use KraftBuiltinPackages;
use JsonException;

class SynchronizeBuiltins extends Command
{
    protected $signature = 'kraft:builtins-sync {--inventory=}';

    protected $description = 'Synchronize extensions supplied with the installed CMS';

    /**
     * @throws JsonException
     */
    public function handle(BuiltinSynchronizer $synchronizer): int
    {
        $run = getenv('KRAFT_BUILTIN_SYNC_RUN');
        if (
            !is_string($run) ||
            !preg_match('/^[0-9]{14}-[a-f0-9]{12}$/D', $run) ||
            !filter_var(getenv('KRAFT_SAFE_MODE'), FILTER_VALIDATE_BOOLEAN)
        ) {
            throw new RuntimeException('Builtin synchronization requires a saved core update operation.');
        }
        $normalize = fn ($path) => PHP_OS_FAMILY === 'Windows'
            ? strtolower(str_replace('\\', '/', $path ?: ''))
            : str_replace('\\', '/', $path ?: '');
        $journal = storage_path('app/updates/'.$run.'/journal.json');
        if ($normalize(realpath($journal)) !== $normalize($journal)) {
            throw new RuntimeException('The builtin synchronization journal is missing or unsafe.');
        }
        $state = json_decode(file_get_contents($journal), true, 64, JSON_THROW_ON_ERROR);
        if (
            ($state['id'] ?? null) !== $run ||
            ($state['state'] ?? null) !== 'migrating' ||
            array_key_exists('extension', $state) ||
            $normalize(realpath($state['root'] ?? '')) !== $normalize(realpath(base_path())) ||
            $normalize(realpath($state['storage'] ?? '')) !== $normalize(realpath(storage_path())) ||
            !isset($state['manifest']['builtins'])
        ) {
            throw new RuntimeException('The builtin synchronization operation does not match this installation.');
        }
        $database = app(DatabaseSnapshots::class)->describe();
        foreach (['database_driver', 'database', 'connection'] as $key) {
            if (!array_key_exists($key, $state) || $state[$key] !== $database[$key]) {
                throw new RuntimeException('The builtin synchronization database does not match its update operation.');
            }
        }
        $encoded = $this->option('inventory');
        $json = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($json === false) {
            throw new RuntimeException('Invalid builtin inventory.');
        }
        $expected = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        require_once base_path('updater/builtins.php');
        if (
            KraftBuiltinPackages::inventory($expected) !==
            KraftBuiltinPackages::inventory($state['manifest']['builtins'])
        ) {
            throw new RuntimeException('The requested builtin inventory does not match its signed update operation.');
        }
        $synchronizer->synchronize($expected);

        return self::SUCCESS;
    }
}
