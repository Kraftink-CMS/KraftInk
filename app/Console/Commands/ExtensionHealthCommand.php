<?php

namespace App\Console\Commands;

use App\Kraft\Extensions\ExtensionManager;
use App\Kraft\Extensions\InstallationReceipts;
use App\Kraft\Games\GameRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Throwable;

class ExtensionHealthCommand extends Command
{
    protected $signature = 'kraft:extension-health {id} {version} {--enable-after-install} {--select-theme} {--operation-run=}';

    protected $description = 'Verify an extension manifest and application health after its migrations';

    public function handle(ExtensionManager $manager): int
    {
        $extension = $manager->all()[$this->argument('id')] ?? null;
        if (
            !$extension ||
            !$extension['valid'] ||
            !version_compare($extension['version'], $this->argument('version'), '==')
        ) {
            $this->error(__('system.extensions.market_package_mismatch'));

            return self::FAILURE;
        }
        $manager->assertInstallable($extension);
        if ($this->option('select-theme') && $extension['type'] !== 'theme') {
            return self::FAILURE;
        }
        if ($extension['type'] === 'game') {
            try {
                app(GameRegistry::class)->get($extension['id']);
            } catch (Throwable) {
                $this->error(__('site.games.invalid_adapter'));

                return self::FAILURE;
            }
        }
        $result = Artisan::call('kraft:health');
        if ($result === 0) {
            $provenance = null;
            $run = $this->option('operation-run');
            if ($run !== null) {
                try {
                    if (!is_string($run) || !preg_match('/^[0-9]{14}-[a-f0-9]{12}$/D', $run)) {
                        return self::FAILURE;
                    }
                    $journal = json_decode(
                        File::get(storage_path('app/updates/'.$run.'/journal.json')),
                        true,
                        32,
                        JSON_THROW_ON_ERROR,
                    );
                    if (
                        !is_array($journal) ||
                        ($journal['id'] ?? null) !== $run ||
                        ($journal['state'] ?? null) !== 'verifying' ||
                        ($journal['root'] ?? null) !== base_path() ||
                        ($journal['storage'] ?? null) !== storage_path() ||
                        ($journal['extension']['id'] ?? null) !== $extension['id'] ||
                        ($journal['manifest']['version'] ?? null) !== $this->argument('version') ||
                        !in_array($journal['extension']['operation'] ?? null, ['install', 'update'], true)
                    ) {
                        return self::FAILURE;
                    }
                    $provenance = $journal['extension']['market_provenance'] ?? null;
                    if ($provenance !== null && !is_array($provenance)) {
                        return self::FAILURE;
                    }
                } catch (Throwable) {
                    return self::FAILURE;
                }
            }
            $manager->confirmInstallation($extension['id'], $this->argument('version'), $provenance);
            if ($extension['type'] === 'game') {
                app(InstallationReceipts::class)->setEnabled($extension['id'], true);
            } elseif ($this->option('select-theme')) {
                $manager->selectTheme($extension['id'], $extension['target']);
            } elseif ($this->option('enable-after-install')) {
                $manager->toggle($extension['id'], true);
            }
        }

        return $result;
    }
}
