<?php

namespace App\Console\Commands;

use App\Kraft\Setup\Installation;
use App\Kraft\System\AuditWebhook;
use Illuminate\Console\Command;

class AuditWebhooksCommand extends Command
{
    protected $signature = 'audit:webhooks {--limit=25}';

    protected $description = 'Deliver pending signed audit webhooks';

    public function handle(AuditWebhook $webhook): int
    {
        if (!app(Installation::class)->installed() && !app()->runningUnitTests()) {
            return self::SUCCESS;
        }
        $limit = $this->option('limit');
        if (!ctype_digit((string) $limit) || (int) $limit < 1 || (int) $limit > 500) {
            return self::INVALID;
        }
        $start = microtime(true);
        for ($count = 0; $count < (int) $limit && microtime(true) - $start < 45; $count++) {
            if (!$webhook->deliver()) {
                break;
            }
        }
        $this->info(__('admin.operations.webhooks_processed', ['count' => $count]));

        return self::SUCCESS;
    }
}
