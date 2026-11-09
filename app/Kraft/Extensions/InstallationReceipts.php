<?php

namespace App\Kraft\Extensions;

use App\Models\ExtensionInstallation;
use App\Kraft\Setup\Installation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\QueryException;
use RuntimeException;
use Random\RandomException;

class InstallationReceipts
{
    private array $cache = [];

    public function all(): array
    {
        if (!app(Installation::class)->installed() && !app()->runningUnitTests()) {
            return [];
        }
        try {
            $config = DB::connection()->getConfig();
            $identity = hash(
                'sha256',
                json_encode(
                    array_intersect_key($config, array_flip(['driver', 'host', 'port', 'database', 'prefix'])),
                    JSON_THROW_ON_ERROR,
                ),
            );

            return $this->cache[$identity] ??= ExtensionInstallation::query()
                ->get()
                ->keyBy('id')
                ->map(fn (ExtensionInstallation $row) => $row->toArray())
                ->all();
        } catch (QueryException $exception) {
            if (!app()->runningUnitTests()) {
                throw $exception;
            }
            return [];
        }
    }

    public function record(array $extension, ?bool $enabled = null, ?array $marketProvenance = null): void
    {
        DB::transaction(function () use ($extension, $enabled, $marketProvenance) {
            $existing = ExtensionInstallation::query()->where('id', $extension['id'])->lockForUpdate()->first();
            if ($existing && $existing->type !== $extension['type']) {
                throw new RuntimeException(__('system.extensions.identifier_mismatch'));
            }
            if (
                $existing &&
                $existing->retired_uuid &&
                !app(BuiltinExtensions::class)->contains($extension['id'], $extension['type'])
            ) {
                throw new RuntimeException(__('system.extensions.restore_before_install'));
            }
            $hash = hash_file('sha256', $extension['path'].'/manifest.json');
            if (!$hash) {
                throw new RuntimeException(__('system.extensions.invalid_manifest'));
            }
            ExtensionInstallation::query()->updateOrCreate(
                ['id' => $extension['id']],
                [
                    'type' => $extension['type'],
                    'version' => $extension['version'],
                    'manifest_hash' => $hash,
                    'market_provenance' => app(MarketSecurity::class)->validateProvenance(
                        $marketProvenance,
                        $extension,
                        $hash,
                    ),
                    'enabled' => $enabled ?? (bool) ($existing->enabled ?? false),
                    'retired_uuid' => null,
                ],
            );
        }, 3);
        $this->refresh();
    }

    /**
     * @throws RandomException
     */
    public function refresh(): void
    {
        $this->cache = [];
        Cache::forever('kraft.extensions.installation-generation', bin2hex(random_bytes(12)));
    }

    public function generation(): string
    {
        return (string) Cache::get('kraft.extensions.installation-generation', '0');
    }

    /**
     * @throws RandomException
     */
    public function setEnabled(string $id, bool $enabled): void
    {
        $query = ExtensionInstallation::query()->where('id', $id);
        if ($enabled) {
            $query->available();
        }
        $changed = $query->update(['enabled' => $enabled, 'updated_at' => now()]);
        if (!$changed && !$query->exists()) {
            throw new RuntimeException(__('system.extensions.install_first'));
        }
        $this->refresh();
    }
}
