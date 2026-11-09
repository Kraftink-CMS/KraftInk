<?php

namespace App\Console\Commands;

use App\Kraft\Migration\ImportManager;
use App\Kraft\Extensions\ExtensionManager;
use Illuminate\Console\Command;

final class ImportCommand extends Command
{
    protected $signature = 'kraft:import {source : Source CMS identifier} {--connection=legacy : Read-only source connection} {--apply : Apply the approved preflight} {--plan= : Preflight hash} {--resume= : Existing run UUID} {--mode=merge : merge or replace} {--existing-users=reject : preserve or reject} {--install-plugins : Enable corresponding locally available plugins}';

    protected $description = 'Analyze or resume a versioned CMS import with encrypted source preservation';

    public function handle(ImportManager $manager): int
    {
        $options = ['mode' => $this->option('mode'), 'existing_users' => $this->option('existing-users')];
        if (!$this->option('apply')) {
            $plan = $manager->analyze($this->argument('source'), $this->option('connection'), $options);
            if ($this->option('install-plugins')) {
                $extensions = app(ExtensionManager::class);
                $available = $extensions->all();
                foreach ($plan['required_plugins'] as $plugin) {
                    if (isset($available[$plugin]) && !$available[$plugin]['enabled']) {
                        $extensions->install($plugin);
                        $extensions->toggle($plugin, true);
                    }
                }
                $extensions->boot();
                $plan = $manager->analyze($this->argument('source'), $this->option('connection'), $options);
            }
            $this->line(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $plan['errors'] === [] ? self::SUCCESS : self::FAILURE;
        }
        if (!$this->option('plan')) {
            $this->error('Run the analysis first, then provide its --plan hash.');

            return self::FAILURE;
        }
        $report = $manager->execute(
            $this->argument('source'),
            $this->option('connection'),
            $this->option('plan'),
            $this->option('resume'),
            function (array $progress): void {
                $this->line(json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            },
            $options,
        );
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
