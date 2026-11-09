<?php

namespace App\Console\Commands;

use App\Kraft\Extensions\Scaffolder;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

class MakeExtensionCommand extends Command
{
    protected $signature = 'kraft:make {type} {name} {--id=} {--namespace=} {--target=}';

    public function getDescription(): string
    {
        return __('system.console.scaffold_description');
    }

    public function handle(Scaffolder $scaffolder): int
    {
        try {
            $package = $scaffolder->create(
                $this->argument('type'),
                $this->argument('name'),
                $this->option('id'),
                $this->option('namespace'),
                $this->option('target'),
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info(__('system.console.scaffold_created', ['id' => $package['id'], 'path' => $package['path']]));
        $this->line(
            __($package['type'] === 'game' ? 'system.console.scaffold_game' : 'system.console.scaffold_inactive'),
        );

        return self::SUCCESS;
    }
}
