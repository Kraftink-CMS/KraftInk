<?php

namespace App\Kraft\System;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use Illuminate\Foundation\Application;

class Diagnostics
{
    public function collect(): array
    {
        $key = 'kraft.health.'.Str::uuid();
        $cache = false;
        $storage = false;
        try {
            Cache::put($key, 'ok', 30);
            $cache = Cache::get($key) === 'ok';
        } catch (Throwable) {
        } finally {
            try {
                Cache::forget($key);
            } catch (Throwable) {
            }
        }
        $path = 'diagnostics/'.Str::uuid().'.txt';
        try {
            $storage = Storage::disk('local')->put($path, 'ok') && Storage::disk('local')->get($path) === 'ok';
        } catch (Throwable) {
        } finally {
            try {
                Storage::disk('local')->delete($path);
            } catch (Throwable) {
            }
        }

        return [
            'php' => PHP_VERSION,
            'laravel' => Application::VERSION,
            'kraftink' => config('manifest.version'),
            'database' => DB::connection()->getDriverName(),
            'cache_driver' => config('cache.default'),
            'cache_ok' => $cache,
            'storage_ok' => $storage,
            'queue' => config('queue.default'),
            'queued_jobs' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : null,
            'failed_jobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null,
            'session_driver' => config('session.driver'),
            'extensions' => collect(['openssl', 'pdo', 'mbstring', 'fileinfo', 'zip', 'sodium', 'intl'])
                ->mapWithKeys(fn ($extension) => [$extension => extension_loaded($extension)])
                ->all(),
        ];
    }
}
