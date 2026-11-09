<?php

namespace App\Kraft\System;

use App\Kraft\Audit;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class CacheOperations
{
    public const array ACTIONS = [
        'content',
        'views_clear',
        'views_warm',
        'routes_clear',
        'routes_warm',
        'config_clear',
        'config_warm',
    ];

    public function run(string $action): void
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Unsupported cache operation.');
        }
        if ($action === 'content') {
            foreach (['kraft.settings', 'kraft.navigation'] as $key) {
                Cache::forget($key);
            }
        } else {
            $command = [
                'views_clear' => 'view:clear',
                'views_warm' => 'view:cache',
                'routes_clear' => 'route:clear',
                'routes_warm' => 'route:cache',
                'config_clear' => 'config:clear',
                'config_warm' => 'config:cache',
            ][$action];
            try {
                if (Artisan::call($command) !== 0) {
                    throw new RuntimeException('Cache operation failed.');
                }
            } catch (Throwable $exception) {
                if (str_ends_with($action, '_warm')) {
                    Artisan::call(str_replace(':cache', ':clear', $command));
                }
                throw $exception;
            }
        }
        Audit::record('system.cache_operation', ['operation' => $action]);
    }
}
