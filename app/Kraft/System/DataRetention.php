<?php

namespace App\Kraft\System;

use App\Events\PruningData;
use App\Kraft\Content\ContentRetention;
use App\Kraft\Media\FileDeletionQueue;
use App\Kraft\Notifications\Notifications;

class DataRetention
{
    public function run(bool $dryRun = false): array
    {
        $event = new PruningData($dryRun);
        foreach ([app(ContentRetention::class), app(TransientRetention::class)] as $retention) {
            foreach ($retention->prune($dryRun) as $scope => $count) {
                $event->add($scope, $count);
            }
        }
        $event->add('audit.entries', app(AuditRetention::class)->purge(dryRun: $dryRun));
        $event->add('privacy.exports', app(PrivacyExportRetention::class)->prune($dryRun));
        $event->add('notifications.delivery_payloads', app(Notifications::class)->prune($dryRun));
        event($event);
        foreach (app(FileDeletionQueue::class)->prune($dryRun) as $scope => $count) {
            $event->add($scope, $count);
        }

        return $event->counts();
    }
}
