<?php

namespace App\Kraft\Migration\Adapters\MineWeb;

use App\Kraft\Migration\CoreMappings as CoreRecords;
use App\Kraft\Migration\ImportContext;
use App\Kraft\Migration\ContentAccess;
use WeakMap;

final class CoreMappings
{
    private ?WeakMap $sourceLocks = null;

    public function __construct(private readonly CoreRecords $records, private readonly ContentAccess $access)
    {
    }

    public function role(array $row, ImportContext $context): array
    {
        $color = $row['color'] ?? null;
        if (is_numeric($color) && (int) $color >= 0 && (int) $color <= 16777215) {
            $row['color'] = sprintf('#%06x', (int) $color);
        }

        return $this->records->role($row, $context);
    }

    public function user(array $row, ImportContext $context): array
    {
        $user = $context->adapter->user($row, $context);
        $user['locked'] = $user['locked'] || $this->sourceLocked($row['id'], $context);

        return $this->records->user($row, $context, $user);
    }

    public function content(array $row, ImportContext $context, string $kind): array
    {
        return $this->records->content($row, $context, $kind, $this->access->resolve($row, $context, $kind));
    }

    public function twoFactor(array $row, ImportContext $context): array
    {
        return !empty($row['enabled'])
            ? $this->records->lockUser($row, $context)
            : ['target_table' => null, 'target_id' => null];
    }

    private function sourceLocked(int|string $id, ImportContext $context): bool
    {
        $this->sourceLocks ??= new WeakMap();
        if (!isset($this->sourceLocks[$context])) {
            $locks = [];
            foreach ($context->sourceRows('bans') ?? [] as $ban) {
                if (!empty($ban['user_id']) && empty($ban['removed_at'])) {
                    $locks[(string) $ban['user_id']] = true;
                }
            }
            foreach ($context->sourceRows('users__twofactorauth') ?? [] as $factor) {
                if (
                    !empty($factor['user_id']) &&
                    (!array_key_exists('enabled', $factor) || !empty($factor['enabled']))
                ) {
                    $locks[(string) $factor['user_id']] = true;
                }
            }
            $this->sourceLocks[$context] = $locks;
        }

        return isset($this->sourceLocks[$context][(string) $id]);
    }
}
