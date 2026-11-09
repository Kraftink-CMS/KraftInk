<?php

namespace App\Console\Commands;

use App\Kraft\Setup\Installation;
use App\Kraft\System\DataRetention;
use App\Exceptions\OperationBusy;
use App\Kraft\System\OperationLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

class MaintenanceCommand extends Command
{
    protected $signature = 'kraft:maintenance {--dry-run}';

    protected $description = 'Clean expired logs, histories, temporary data and abandoned files';

    public function handle(Installation $installation, DataRetention $retention): int
    {
        if (!$installation->installed() && !app()->runningUnitTests()) {
            return self::SUCCESS;
        }
        $path = storage_path('framework/maintenance.lock');
        File::ensureDirectoryExists(dirname($path));
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Cannot open the maintenance lock.');
        }
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return self::SUCCESS;
            }

            return app(OperationLock::class)->shared(
                function () use ($retention) {
                    foreach ($retention->run((bool) $this->option('dry-run')) as $scope => $count) {
                        $this->line($scope.': '.$count);
                    }

                    return self::SUCCESS;
                },
            );
        } catch (OperationBusy) {
            return self::SUCCESS;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
