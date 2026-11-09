<?php

namespace App\Console\Commands;

use App\Kraft\Content\Discord\Announcements;
use Illuminate\Console\Command;

class ContentAnnouncementsCommand extends Command
{
    protected $signature = 'content:announcements {--limit=50}';

    protected $description = 'Deliver queued public news announcements to the configured Discord webhook';

    public function handle(Announcements $announcements): int
    {
        $this->line((string) $announcements->run((int) $this->option('limit')));

        return self::SUCCESS;
    }
}
