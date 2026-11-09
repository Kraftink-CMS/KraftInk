<?php

namespace App\Console\Commands;

use App\Kraft\Extensions\ExtensionUpdateManager;
use App\Kraft\Updates\UpdateManager;
use Illuminate\Console\Command;

class ExtensionUpdateCommand extends Command
{
    protected $signature = 'kraft:extension-update {archive} {--run}';

    protected $description = 'Prepare or apply an installed plugin or theme upgrade';

    public function handle(ExtensionUpdateManager $extensions, UpdateManager $updates): int
    {
        $id = $extensions->stage($this->argument('archive'));
        $this->info($id);
        if (!$this->option('run')) {
            return self::SUCCESS;
        }
        $process = $updates->run($id);
        $this->line($process->getOutput());
        $this->line($process->getErrorOutput());

        return $process->getExitCode();
    }
}
