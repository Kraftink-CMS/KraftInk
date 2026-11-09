<?php

namespace App\Kraft\Notifications;

use App\Kraft\Extensions\ExtensionManager;
use InvalidArgumentException;

class NotificationRegistry
{
    private array $types = [];

    public function register(
        string $owner,
        string $type,
        string $label,
        callable $render,
        callable $visible,
        array $channels = ['native', 'email'],
        array $defaults = ['native' => true, 'email' => false],
        ?callable $webhook = null,
    ): void {
        if (
            !preg_match('/^[a-z][a-z0-9-]{1,49}$/D', $owner) ||
            !str_starts_with($type, $owner.'.') ||
            !preg_match('/^[a-z][a-z0-9_.-]{1,119}$/D', $type) ||
            $label === '' ||
            !$channels ||
            array_diff($channels, ['native', 'email']) ||
            array_diff(array_keys($defaults), $channels) ||
            count($channels) !== count(array_unique($channels)) ||
            count(array_filter($defaults, 'is_bool')) !== count($defaults) ||
            isset($this->types[$type])
        ) {
            throw new InvalidArgumentException('Invalid notification type.');
        }
        $this->types[$type] = compact('owner', 'type', 'label', 'render', 'visible', 'channels', 'defaults', 'webhook');
    }

    public function all(): array
    {
        $contributors = app(ExtensionManager::class)->runtimeContributors();

        return array_filter($this->types, fn (array $type) => isset($contributors[$type['owner']]));
    }

    public function get(string $type): ?array
    {
        return $this->all()[$type] ?? null;
    }
}
