<?php

namespace App\Console\Commands;

use App\Kraft\Extensions\ExtensionManager;
use App\Kraft\System\OperationLock;
use Illuminate\Console\Command;

class ExtensionsCommand extends Command
{
    protected $signature = 'kraft:extensions {action=list} {id?}';

    protected $description = 'List, enable or disable an extension without deleting its data';

    public function handle(ExtensionManager $manager): int
    {
        if ($this->argument('action') === 'list') {
            $this->table(
                ['ID', 'Type', 'Version', 'Enabled', 'Valid'],
                array_map(
                    fn ($item) => [
                        $item['id'],
                        $item['type'],
                        $item['version'],
                        $item['enabled'] ? 'yes' : 'no',
                        $item['valid'] ? 'yes' : 'no',
                    ],
                    $manager->all(),
                ),
            );

            return self::SUCCESS;
        }
        if (!in_array($this->argument('action'), ['enable', 'disable'], true) || !$this->argument('id')) {
            return self::INVALID;
        }
        app(OperationLock::class)->shared(
            fn () => $manager->toggle(
                $this->argument('id'),
                $this->argument('action') === 'enable',
            ),
        );
        $this->info('Extension state saved.');

        return self::SUCCESS;
    }
}
