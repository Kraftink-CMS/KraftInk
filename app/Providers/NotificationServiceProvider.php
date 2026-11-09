<?php

namespace App\Providers;

use App\Events\AccountDeleting;
use App\Events\ExportingPersonalData;
use App\Kraft\Notifications\NotificationPreferences;
use App\Kraft\Notifications\NotificationRegistry;
use App\Kraft\Notifications\Notifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NotificationRegistry::class);
        $this->app->scoped(NotificationPreferences::class);
        $this->app->scoped(Notifications::class);
    }

    public function boot(): void
    {
        Event::listen(ExportingPersonalData::class, function (ExportingPersonalData $event): void {
            $event->data['notification_preferences'] = $event->user
                ->notificationPreferences()
                ->orderBy('id')
                ->select(['type', 'channel', 'enabled'])
                ->cursor();
            $event->data['notification_deliveries'] = $event->user
                ->notificationDeliveries()
                ->orderBy('id')
                ->select(['type', 'channel', 'status', 'created_at', 'delivered_at'])
                ->cursor();
        });
        Event::listen(
            AccountDeleting::class,
            function (AccountDeleting $event): void {
                $service = app(Notifications::class);
                $owners = $service->deliveries()->where('user_id', $event->user->id)->distinct()->pluck('owner');
                foreach ($owners as $owner) {
                    $service->forget($owner, user: $event->user->id);
                }
                $event->user->notificationPreferences()->delete();
            },
        );
    }
}
