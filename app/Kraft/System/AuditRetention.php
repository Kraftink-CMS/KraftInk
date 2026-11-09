<?php

namespace App\Kraft\System;

use App\Kraft\Audit;
use App\Kraft\Settings;
use App\Models\Audit\AuditEntry;
use InvalidArgumentException;

class AuditRetention
{
    public function purge(?int $days = null, bool $dryRun = false): int
    {
        if ($days === null) {
            /** @var Settings $settings */
            $settings = app(Settings::class);
            $days = (int) $settings->get('audit.retention_days', 365);
        }
        if ($days < 7 || $days > 3650) {
            throw new InvalidArgumentException('Invalid audit retention.');
        }
        $cutoff = now()->subDays($days);
        $query = AuditEntry::query()->where('created_at', '<', $cutoff);
        if ($dryRun) {
            return $query->count();
        }
        $ids = $query->orderBy('id')->limit(1000)->pluck('id');
        $count = AuditEntry::query()->whereIn('id', $ids)->delete();
        if ($count > 0) {
            Audit::record('audit.purged', ['days' => $days, 'count' => $count]);
        }

        return $count;
    }
}
