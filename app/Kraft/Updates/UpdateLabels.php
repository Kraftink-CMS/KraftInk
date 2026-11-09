<?php

namespace App\Kraft\Updates;

class UpdateLabels
{
    public function all(): array
    {
        $labels = [
            'states' => __('system.updates.state'),
            'preparing' => __('system.updates.preparing'),
            'starting' => __('system.updates.starting'),
            'ready' => __('system.updates.ready_help'),
            'running' => __('system.updates.running'),
            'failure' => __('system.updates.failure'),
            'finished' => __('system.updates.finished'),
            'connection_lost' => __('system.updates.connection_lost'),
            'prepare_unknown' => __('system.updates.prepare_unknown'),
            'tracking_unavailable' => __('system.updates.tracking_unavailable'),
            'unknown' => __('system.updates.unknown'),
            'another_running' => __('system.updates.another_running'),
            'not_started' => __('system.updates.not_started'),
            'heading' => __('system.updates.history'),
            'progress' => __('system.updates.progress'),
            'run' => __('system.updates.run'),
            'empty_title' => __('admin.dashboard.new_installation'),
            'empty_help' => __('system.updates.history_empty'),
        ];

        return [
            'install' => array_replace_recursive($labels, __('system.updates.installation')),
            'uninstall' => array_replace_recursive($labels, __('system.updates.uninstallation')),
            'update' => $labels,
        ];
    }
}
