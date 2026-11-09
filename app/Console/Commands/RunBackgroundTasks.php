<?php

namespace App\Console\Commands;

use App\Kraft\Setup\Installation;
use App\Exceptions\OperationBusy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use RuntimeException;

class RunBackgroundTasks extends Command
{
    protected $signature = 'kraft:tasks-run';

    protected $description = 'Process a bounded batch of queued background tasks';

    public function handle(Installation $installation): int
    {
        if (!$installation->installed() && !app()->runningUnitTests()) {
            return self::SUCCESS;
        }
        $path = storage_path('framework/background-tasks.lock');
        File::ensureDirectoryExists(dirname($path));
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Cannot open the background task lock.');
        }
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return self::SUCCESS;
            }

            return Artisan::call(
                'queue:work',
                [
                    'connection' => 'database',
                    '--stop-when-empty' => true,
                    '--sleep' => 1,
                    '--max-jobs' => 25,
                    '--max-time' => 40,
                    '--tries' => 3,
                ],
                $this->output,
            );
        } catch (OperationBusy) {
            return self::SUCCESS;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
