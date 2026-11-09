<?php

namespace App\Console\Commands;

use App\Kraft\Content\ContentPublishing;
use App\Kraft\Setup\Installation;
use Illuminate\Console\Command;
use App\Kraft\System\OperationLock;

class ContentPublishCommand extends Command
{
    protected $signature = 'kraft:content-publish';

    protected $description = 'Dispatch pending publication events for published content';

    public function handle(ContentPublishing $publishing, Installation $installation): int
    {
        return app(OperationLock::class)->shared(
            fn () => $this->publish($publishing, $installation),
        );
    }

    private function publish(ContentPublishing $publishing, Installation $installation): int
    {
        if ($installation->installed() || app()->runningUnitTests()) {
            $publishing->dispatchDue();
        }

        return self::SUCCESS;
    }
}
