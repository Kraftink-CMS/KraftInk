<?php

namespace App\Kraft\System;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Traversable;
use DateTimeInterface;
use JsonSerializable;
use Throwable;
use Random\RandomException;
use JsonException;

class DataExportSnapshot
{
    private array $files = [];

    private int $bytes = 0;

    private int $diskBytes = 0;

    private int $records = 0;

    private float $started;

    public function __construct()
    {
        $this->started = microtime(true);
    }

    public function freeze(mixed $value, int $depth = 0): mixed
    {
        try {
            $this->guard();
            if ($depth > 32) {
                throw new RuntimeException('Export nesting exceeds the limit.');
            }
            if ($value instanceof Traversable) {
                return $this->iterable($value);
            }
            if ($value instanceof DateTimeInterface) {
                return $value->format(DATE_ATOM);
            }
            if ($value instanceof JsonSerializable) {
                return $this->freeze($value->jsonSerialize(), $depth + 1);
            }
            if (is_object($value)) {
                return $this->freeze(get_object_vars($value), $depth + 1);
            }
            if (is_array($value)) {
                $result = [];
                foreach ($value as $key => $entry) {
                    $this->bytes += strlen((string) $key);
                    $result[$key] = $this->freeze($entry, $depth + 1);
                }

                return $result;
            }
            if (is_resource($value)) {
                throw new RuntimeException('Unsupported export value.');
            }
            $this->bytes += is_string($value) ? strlen($value) : 8;
            $this->guard();

            return $value;
        } catch (Throwable $exception) {
            $this->cleanup();
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            throw ValidationException::withMessages(['account' => __('site.account.privacy.exports.export_failed')]);
        }
    }

    /**
     * @throws RandomException
     * @throws JsonException
     */
    private function iterable(Traversable $values): LazyCollection
    {
        $directory = storage_path('app/private/privacy-exports');
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new RuntimeException('Private export storage unavailable.');
        }
        $privateRoot = realpath(storage_path('app/private'));
        $resolved = realpath($directory);
        if (
            !$privateRoot ||
            !$resolved ||
            !str_starts_with(
                str_replace('\\', '/', $resolved),
                rtrim(str_replace('\\', '/', $privateRoot), '/').'/',
            ) ||
            !chmod($directory, 0700)
        ) {
            throw new RuntimeException('Private export storage unavailable.');
        }
        $free = disk_free_space($directory);
        if ($free === false || $free < (int) config('privacy.export_min_free_bytes')) {
            throw new RuntimeException('Insufficient export storage.');
        }
        $path = $directory.'/'.bin2hex(random_bytes(20)).'.jsonl.encrypted';
        $handle = fopen($path, 'x+b');
        if (!$handle) {
            throw new RuntimeException('Cannot create private export.');
        }
        $this->files[] = $path;
        try {
            if (!chmod($path, 0600)) {
                throw new RuntimeException('Cannot protect private export.');
            }
            foreach ($values as $key => $value) {
                $this->guard();
                $this->records++;
                if (
                    $this->records % 100 === 0 &&
                    disk_free_space($directory) < (int) config('privacy.export_min_free_bytes')
                ) {
                    throw new RuntimeException('Insufficient export storage.');
                }
                $json = json_encode(
                    ['key' => $key, 'value' => $value],
                    JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
                    64,
                );
                if (strlen($json) > (int) config('privacy.export_max_record_bytes')) {
                    throw new RuntimeException('Export record exceeds the limit.');
                }
                $this->bytes += strlen($json);
                $line = Crypt::encryptString($json)."\n";
                $this->diskBytes += strlen($line);
                $this->guard();
                if (
                    $this->diskBytes > (int) config('privacy.export_max_bytes') * 2 ||
                    fwrite($handle, $line) !== strlen($line)
                ) {
                    throw new RuntimeException('Export storage limit exceeded.');
                }
            }
            fflush($handle);
        } finally {
            fclose($handle);
        }
        $checksum = hash_file('sha256', $path);

        return new LazyCollection(function () use ($path, $checksum) {
            if (!is_file($path) || !hash_equals($checksum, hash_file('sha256', $path))) {
                throw new RuntimeException('Private export integrity check failed.');
            }
            $handle = fopen($path, 'rb');
            if (!$handle) {
                throw new RuntimeException('Private export unavailable.');
            }
            try {
                while (($line = fgets($handle, (int) config('privacy.export_max_record_bytes') * 2 + 2048)) !== false) {
                    if (!str_ends_with($line, "\n")) {
                        throw new RuntimeException('Invalid export frame.');
                    }
                    $record = json_decode(Crypt::decryptString(rtrim($line, "\n")), true, 64, JSON_THROW_ON_ERROR);
                    yield $record['key'] => $record['value'];
                }
            } finally {
                fclose($handle);
            }
        });
    }

    private function guard(): void
    {
        if (
            $this->bytes > (int) config('privacy.export_max_bytes') ||
            microtime(true) - $this->started > (int) config('privacy.export_max_seconds')
        ) {
            throw new RuntimeException('Export preparation limit exceeded.');
        }
    }

    public function cleanup(): void
    {
        foreach ($this->files as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->files = [];
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}
