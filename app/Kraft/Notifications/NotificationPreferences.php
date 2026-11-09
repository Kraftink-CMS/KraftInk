<?php

namespace App\Kraft\Notifications;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Models\Accounts\User;
use App\Models\Notifications\NotificationPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class NotificationPreferences
{
    public function for(User $user): array
    {
        $saved = $user->notificationPreferences()->get();
        $result = [];
        foreach (app(NotificationRegistry::class)->all() as $type => $definition) {
            $result[$type] = $definition;
            $result[$type]['values'] = [];
            foreach ($definition['channels'] as $channel) {
                /** @var NotificationPreference|null $row */
                $row = $saved->first(fn ($entry) => $entry->type === $type && $entry->channel === $channel);
                $result[$type]['values'][$channel] = $row
                    ? (bool) $row->enabled
                    : $definition['defaults'][$channel] ?? false;
            }
        }

        return $result;
    }

    public function enabled(User $user, array $type, string $channel): bool
    {
        $value = $user
            ->notificationPreferences()
            ->where('type', $type['type'])
            ->where('channel', $channel)
            ->value('enabled');

        return $value === null ? $type['defaults'][$channel] ?? false : (bool) $value;
    }

    public function save(User $user, array $values): void
    {
        $definitions = app(NotificationRegistry::class)->all();
        foreach ($values as $type => $channels) {
            $definition = $definitions[$type] ?? null;
            if (!$definition || !is_array($channels) || array_diff(array_keys($channels), $definition['channels'])) {
                throw ValidationException::withMessages(['preferences' => __('notifications.invalid_preferences')]);
            }
            foreach ($channels as $enabled) {
                $validator = Validator::make(['enabled' => $enabled], ['enabled' => 'required|boolean']);
                if ($validator->fails()) {
                    throw ValidationException::withMessages(['preferences' => __('notifications.invalid_preferences')]);
                }
            }
        }
        DB::transaction(function () use ($user, $values): void {
            $current = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if (!$current || $current->anonymized_at || $current->import_locked || $current->isBanned()) {
                throw new HttpException(403);
            }
            foreach ($values as $type => $channels) {
                foreach ($channels as $channel => $enabled) {
                    $user
                        ->notificationPreferences()
                        ->updateOrCreate(['type' => $type, 'channel' => $channel], ['enabled' => (bool) $enabled]);
                }
            }
        });
    }
}
