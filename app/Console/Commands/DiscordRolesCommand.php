<?php

namespace App\Console\Commands;

use App\Kraft\OAuth\Discord\RoleConnections;
use App\Kraft\Setup\Installation;
use Illuminate\Console\Command;
use JsonException;

class DiscordRolesCommand extends Command
{
    protected $signature = 'discord:roles-sync {--limit=100}';

    protected $description = 'Synchronize consented Discord role criteria and pending removals';

    /**
     * @throws JsonException
     */
    public function handle(RoleConnections $connections): int
    {
        if (!app(Installation::class)->installed() && !app()->runningUnitTests()) {
            return self::SUCCESS;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000],
        ]);
        if ($limit === false) {
            $this->error('The limit must be between 1 and 1000.');

            return self::INVALID;
        }
        $this->line(json_encode($connections->process($limit), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
