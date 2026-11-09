<?php

namespace App\Kraft\System;

use App\Exceptions\OperationBusy;
use App\Kraft\Site\SiteIdentity;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobPopped;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use WeakMap;
use KraftOperationGate;
use Throwable;

class QueueOperationGuard
{
    private ?OperationLease $pending = null;

    private WeakMap $jobs;

    private ?string $generation = null;

    public function __construct()
    {
        $this->jobs = new WeakMap();
    }

    public function starting(CommandStarting $event): void
    {
        if (!in_array($event->command, ['queue:work', 'queue:listen'], true)) {
            return;
        }
        $lock = app(OperationLock::class);
        $lease = $lock->acquireBootstrap(false);
        try {
            $this->generation = KraftOperationGate::generation($lock->path());
        } finally {
            $lease->release();
        }
    }

    public function canStart(Looping $event): ?bool
    {
        try {
            app(OperationLock::class)->shared(fn () => $this->assertGeneration());
        } catch (OperationBusy) {
            return false;
        }

        return null;
    }

    public function beforePop(JobPopping $event): void
    {
        $this->idle();
        $this->pending = app(OperationLock::class)->acquireShared();
        try {
            $this->assertGeneration();
        } catch (Throwable $exception) {
            $this->idle();
            throw $exception;
        }
    }

    public function afterPop(JobPopped $event): void
    {
        if (!$event->job) {
            $this->idle();

            return;
        }
        $this->jobs[$event->job] = $this->pending ?? app(OperationLock::class)->acquireShared();
        $this->pending = null;
    }

    public function beforeProcessing(JobProcessing $event): void
    {
        $this->jobs[$event->job] ??= app(OperationLock::class)->acquireShared();
        app(SiteIdentity::class)->apply();
        app(MailConfiguration::class)->apply();
    }

    public function finished(JobAttempted $event): void
    {
        ($this->jobs[$event->job] ?? null)?->release();
        unset($this->jobs[$event->job]);
    }

    public function idle(): void
    {
        $this->pending?->release();
        $this->pending = null;
    }

    private function assertGeneration(): void
    {
        if (
            $this->generation !== null &&
            !hash_equals($this->generation, KraftOperationGate::generation(app(OperationLock::class)->path()))
        ) {
            app('queue.worker')->shouldQuit = true;
            throw new OperationBusy();
        }
    }
}
