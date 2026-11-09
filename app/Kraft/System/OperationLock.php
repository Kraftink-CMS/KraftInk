<?php

namespace App\Kraft\System;

use App\Exceptions\OperationBusy;
use Closure;
use KraftOperationGate;
use Throwable;

class OperationLock
{
    private mixed $handle = null;

    private int $depth = 0;

    private bool $exclusive = false;

    private bool $bootstrap = false;

    private string $bootstrapGeneration = '';

    private bool $retired = false;

    private bool $staging = false;

    private string $lastGeneration = '';

    public function shared(Closure $operation): mixed
    {
        $lease = $this->acquireShared();
        try {
            return $operation();
        } finally {
            $lease->release();
        }
    }

    public function exclusive(Closure $operation): mixed
    {
        $lease = $this->acquireExclusive();
        try {
            return $operation();
        } finally {
            $lease->release();
        }
    }

    public function acquireShared(): OperationLease
    {
        return $this->acquire(false);
    }

    public function acquireExclusive(): OperationLease
    {
        return $this->acquire(true);
    }

    public function acquireBootstrap(bool $exclusive): OperationLease
    {
        $this->adoptBootstrap();
        if (!$this->bootstrap) {
            return $this->acquire($exclusive);
        }
        if ($this->depth > 0 || $this->retired) {
            throw new OperationBusy();
        }
        if ($exclusive) {
            flock($this->handle, LOCK_UN);
            if (!flock($this->handle, LOCK_EX | LOCK_NB)) {
                $this->close();
                throw new OperationBusy();
            }
            try {
                if (
                    !hash_equals($this->bootstrapGeneration, KraftOperationGate::generation($this->path())) ||
                    KraftOperationGate::updating(storage_path())
                ) {
                    throw new OperationBusy();
                }
                KraftOperationGate::advance($this->path());
            } catch (Throwable) {
                $this->close();
                throw new OperationBusy();
            }
        }
        $this->exclusive = $exclusive;
        $this->lastGeneration = KraftOperationGate::generation($this->path());
        $this->bootstrap = false;
        $this->depth = 1;

        return new OperationLease(fn () => $this->release());
    }

    public function acquireStaging(string $generation): OperationLease
    {
        $this->staging = true;
        try {
            $lease = $this->acquireShared();
            if (
                !hash_equals($generation, KraftOperationGate::generation($this->path())) ||
                KraftOperationGate::updating(storage_path())
            ) {
                $lease->release();
                throw new OperationBusy();
            }
        } catch (Throwable $exception) {
            $this->staging = false;
            throw $exception;
        }

        return new OperationLease(function () use ($lease): void {
            $lease->release();
            if ($this->idle()) {
                $this->staging = false;
            }
        });
    }

    public function lastGeneration(): string
    {
        return $this->lastGeneration;
    }

    public function retireRequest(): void
    {
        $this->retired = true;
    }

    public function idle(): bool
    {
        return $this->depth === 0 && !is_resource($this->handle);
    }

    public function path(): string
    {
        return config('operations.lock_path') ?? storage_path('app/operations.lock');
    }

    private function acquire(bool $exclusive): OperationLease
    {
        if ($this->retired && !$this->staging) {
            throw new OperationBusy();
        }
        $this->adoptBootstrap();
        if ($this->bootstrap && $exclusive) {
            throw new OperationBusy();
        }
        if ($this->depth > 0) {
            if ($exclusive && !$this->exclusive) {
                throw new OperationBusy();
            }
            $this->depth++;

            return new OperationLease(fn () => $this->release());
        }
        if ($this->bootstrap) {
            $this->depth = 1;

            return new OperationLease(fn () => $this->release());
        }
        $path = $this->path();
        try {
            $handle = KraftOperationGate::open($path);
        } catch (Throwable) {
            throw new OperationBusy();
        }
        if (!flock($handle, ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
            fclose($handle);
            throw new OperationBusy();
        }
        $this->handle = $handle;
        if ($exclusive) {
            try {
                KraftOperationGate::advance($path);
            } catch (Throwable) {
                $this->close();
                throw new OperationBusy();
            }
        }
        $this->exclusive = $exclusive;
        $this->lastGeneration = KraftOperationGate::generation($path);
        $this->depth = 1;

        return new OperationLease(fn () => $this->release());
    }

    private function release(): void
    {
        if ($this->depth === 0 || --$this->depth > 0) {
            return;
        }
        if ($this->bootstrap) {
            return;
        }
        try {
            if ($this->exclusive) {
                KraftOperationGate::advance($this->path());
                $this->lastGeneration = KraftOperationGate::generation($this->path());
            }
        } catch (Throwable) {
            throw new OperationBusy();
        } finally {
            $this->close();
        }
    }

    private function adoptBootstrap(): void
    {
        if (!class_exists(KraftOperationGate::class, false)) {
            require_once base_path('bootstrap/operations.php');
        }
        $state = $GLOBALS['kraft.operations'] ?? null;
        if (!is_array($state) || !is_resource($state['handle'] ?? null)) {
            return;
        }
        $normalize = fn (string $path) => PHP_OS_FAMILY === 'Windows'
            ? strtolower(str_replace('\\', '/', $path))
            : $path;
        if ($normalize($state['path']) !== $normalize($this->path()) || is_resource($this->handle)) {
            throw new OperationBusy();
        }
        $this->handle = $state['handle'];
        $this->bootstrapGeneration = $state['generation'];
        $this->bootstrap = true;
        unset($GLOBALS['kraft.operations']);
    }

    private function close(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
        $this->handle = null;
        $this->exclusive = false;
        $this->bootstrap = false;
    }

    public function __destruct()
    {
        $this->close();
    }
}
