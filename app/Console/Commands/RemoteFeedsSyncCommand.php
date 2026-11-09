<?php

namespace App\Console\Commands;

use App\Kraft\Extensions\MarketSecurity;
use App\Kraft\SiteAnnouncements;
use Illuminate\Console\Command;

class RemoteFeedsSyncCommand extends Command
{
    protected $signature = 'kraft:remote-feeds-sync';

    protected $description = 'Refresh distributor revocations and site announcements without removing extension data';

    public function handle(MarketSecurity $security, SiteAnnouncements $announcements): int
    {
        $results = [...array_values($security->synchronize()), ...array_values($announcements->synchronize())];
        if (in_array(false, $results, true)) {
            $this->warn(__('market.security.failed'));

            return self::FAILURE;
        }
        $this->info(__('market.security.synced'));

        return self::SUCCESS;
    }
}
