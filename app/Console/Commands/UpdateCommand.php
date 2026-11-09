<?php

namespace App\Console\Commands;

use App\Kraft\Updates\UpdateManager;
use Illuminate\Console\Command;

class UpdateCommand extends Command
{
    protected $signature = 'kraft:update {archive?} {--run=} {--recover=}';

    protected $description = 'Stage, run or recover a signed KraftInk update';

    public function handle(UpdateManager $manager): int
    {
        if ($id = $this->option('recover') ?: $this->option('run')) {
            $process = $manager->run($id, (bool) $this->option('recover'));
            $this->line($process->getOutput());
            $this->line($process->getErrorOutput());

            return $process->getExitCode();
        }
        if (!$this->argument('archive')) {
            $this->error('Provide an archive, --run or --recover.');

            return self::FAILURE;
        }
        $this->info($manager->stage($this->argument('archive')));

        return self::SUCCESS;
    }
}
