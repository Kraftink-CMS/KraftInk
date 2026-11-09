<?php

namespace App\Console\Commands;

use App\Kraft\Notifications\Notifications;
use App\Kraft\Setup\Installation;
use Illuminate\Console\Command;

class DeliverNotifications extends Command
{
    protected $signature = 'notifications:deliver {--limit=100}';

    protected $description = 'Deliver a bounded batch of community notifications';

    public function handle(Notifications $notifications): int
    {
        if (!app(Installation::class)->installed() && !app()->runningUnitTests()) {
            return self::SUCCESS;
        }
        $this->line((string) $notifications->run((int) $this->option('limit')));

        return self::SUCCESS;
    }
}
