<?php

namespace App\Console\Commands;

use App\Kraft\Extensions\ExtensionManager;
use App\Kraft\Games\GameRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Throwable;

class HealthCommand extends Command
{
    protected $signature = 'kraft:health {--expected-version=}';

    protected $description = 'Verify database, extension manifests and application routes';

    public function handle(ExtensionManager $manager): int
    {
        DB::connection()->getPdo();
        foreach (['bootstrap/prepare.php', 'bootstrap/operations.php', 'public/index.php'] as $file) {
            if (!is_file(base_path($file))) {
                $this->error(__('system.coordination.bootstrap_failed'));

                return self::FAILURE;
            }
            $lint = new Process([PHP_BINARY, '-l', base_path($file)]);
            $lint->setTimeout(10);
            $lint->disableOutput();
            $lint->run();
            if (!$lint->isSuccessful()) {
                $this->error(__('system.coordination.bootstrap_failed'));

                return self::FAILURE;
            }
        }
        if ($this->option('expected-version') && config('manifest.version') !== $this->option('expected-version')) {
            $this->error('The installed version does not match the signed release.');

            return self::FAILURE;
        }
        foreach (['users', 'roles', 'settings', 'content_entries'] as $table) {
            if (!Schema::hasTable($table)) {
                $this->error('Missing table: '.$table);

                return self::FAILURE;
            }
        }
        $extensions = $manager->all();
        $probe = getenv('KRAFT_PROBE_EXTENSION');
        if ($probe && ($extensions[$probe]['type'] ?? null) === 'game') {
            try {
                app(GameRegistry::class)->get($probe);
            } catch (Throwable) {
                $this->error(__('site.games.invalid_adapter'));

                return self::FAILURE;
            }
        }
        if (!app('router')->getRoutes()->getByName('site.home.index')) {
            return self::FAILURE;
        }
        $this->info('KraftInk healthy');

        return self::SUCCESS;
    }
}
