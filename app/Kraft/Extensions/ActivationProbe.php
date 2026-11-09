<?php

namespace App\Kraft\Extensions;

use App\Kraft\System\PhpBinary;
use App\Kraft\System\ProcessEnvironment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Throwable;

class ActivationProbe
{
    public function verify(string $id): void
    {
        $connection = config('database.connections.'.config('database.default'));
        try {
            $result = Process::path(base_path())
                ->timeout(45)
                ->env([
                    ...app(ProcessEnvironment::class)->variables(),
                    'KRAFT_PROBE_EXTENSION' => $id,
                    'KRAFT_PROBE_PATHS' => json_encode(
                        [
                            'extensions' => config('kraft.extensions'),
                            'storage' => storage_path(),
                        ],
                        JSON_THROW_ON_ERROR,
                    ),
                    'KRAFT_SAFE_MODE' => 'false',
                    'LOG_CHANNEL' => 'stderr',
                    'DB_CONNECTION' => config('database.default'),
                    'DB_URL' => $connection['url'] ?? false,
                    'DB_DATABASE' => $connection['database'] ?? '',
                    'DB_HOST' => $connection['host'] ?? '',
                    'DB_PORT' => isset($connection['port']) ? (string) $connection['port'] : '',
                    'DB_USERNAME' => $connection['username'] ?? '',
                    'DB_PASSWORD' => $connection['password'] ?? '',
                    'APP_ROUTES_CACHE' => storage_path('framework/cache/probe-'.bin2hex(random_bytes(8)).'.php'),
                ])
                ->run([app(PhpBinary::class)->path(), base_path('artisan'), 'kraft:health']);
            if ($result->successful()) {
                return;
            }
            Log::warning('Extension activation probe failed', [
                'extension' => $id,
                'exit' => $result->exitCode(),
                'output' => mb_substr($result->errorOutput().$result->output(), 0, 4000),
            ]);
        } catch (Throwable $error) {
            Log::warning('Extension activation probe unavailable', [
                'extension' => $id,
                'error' => $error->getMessage(),
            ]);
        }
        throw ValidationException::withMessages(['extension' => __('system.extensions.probe_failed')]);
    }
}
