<?php

namespace App\Console\Commands;

use App\Kraft\Extensions\PackageUninstaller;
use Illuminate\Console\Command;

class ExtensionUninstallCommand extends Command
{
    protected $signature = 'kraft:extension-uninstall {id} {version} {run}';

    protected $description = 'Remove extension data inside a protected uninstall operation';

    public function handle(PackageUninstaller $packages): int
    {
        $packages->uninstall($this->argument('id'), $this->argument('version'), $this->argument('run'));

        return self::SUCCESS;
    }
}
