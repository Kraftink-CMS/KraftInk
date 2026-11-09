<?php

namespace App\Providers;

use App\Kraft\System\MailConfiguration;
use App\Kraft\System\QueueOperationGuard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobPopped;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerIdle;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class SystemServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->singleton(QueueOperationGuard::class);
        Mail::extend('kraftink', fn () => app(MailConfiguration::class)->transport());
        Event::listen(CommandStarting::class, [QueueOperationGuard::class, 'starting']);
        Event::listen(Looping::class, [QueueOperationGuard::class, 'canStart']);
        Event::listen(JobPopping::class, [QueueOperationGuard::class, 'beforePop']);
        Event::listen(JobPopped::class, [QueueOperationGuard::class, 'afterPop']);
        Event::listen(JobProcessing::class, [QueueOperationGuard::class, 'beforeProcessing']);
        Event::listen(JobAttempted::class, [QueueOperationGuard::class, 'finished']);
        Event::listen([WorkerIdle::class, WorkerStopping::class], fn () => app(QueueOperationGuard::class)->idle());
    }
}
