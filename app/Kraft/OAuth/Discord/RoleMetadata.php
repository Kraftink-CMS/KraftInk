<?php

namespace App\Kraft\OAuth\Discord;

use App\Events\RegisteringDiscordRoleMetadata;
use App\Kraft\Settings;
use App\Models\Accounts\User;
use Carbon\CarbonImmutable;
use Closure;
use InvalidArgumentException;
use RuntimeException;

class RoleMetadata
{
    private array $definitions = [];

    private bool $registered = false;

    public function __construct()
    {
        $this->register(
            'role_id',
            3,
            'admin.integrations.discord.metadata.role_id.name',
            'admin.integrations.discord.metadata.role_id.description',
            fn (User $user, $roles) => $roles->first()?->id ?? 0,
        );
        $this->register(
            'role_power',
            2,
            'admin.integrations.discord.metadata.role_power.name',
            'admin.integrations.discord.metadata.role_power.description',
            fn (User $user, $roles) => $roles->first()?->position ?? 0,
        );
        $this->register(
            'verified',
            7,
            'admin.integrations.discord.metadata.verified.name',
            'admin.integrations.discord.metadata.verified.description',
            fn (User $user) => (int) $user->hasVerifiedEmail(),
        );
        $this->register(
            'registered_at',
            5,
            'admin.integrations.discord.metadata.registered_at.name',
            'admin.integrations.discord.metadata.registered_at.description',
            fn (User $user) => $user->created_at->toIso8601String(),
        );
    }

    public function register(string $key, int $type, string $name, string $description, Closure $value): void
    {
        if (
            !preg_match('/^[a-z0-9_]{1,50}$/D', $key) ||
            !in_array($type, range(1, 8), true) ||
            isset($this->definitions[$key])
        ) {
            throw new InvalidArgumentException('Invalid or duplicate Discord role metadata.');
        }
        $this->definitions[$key] = compact('key', 'type', 'name', 'description', 'value');
    }

    public function all(): array
    {
        if (!$this->registered) {
            $this->registered = true;
            event(new RegisteringDiscordRoleMetadata($this));
        }

        return $this->definitions;
    }

    public function selected(): array
    {
        /** @var Settings $settings */
        $settings = app(Settings::class);
        $keys = $settings->get('discord.roles.fields', ['role_id', 'role_power']);
        if (
            !is_array($keys) ||
            count($keys) < 1 ||
            count($keys) > 5 ||
            count(array_unique($keys)) !== count($keys) ||
            array_diff($keys, array_keys($this->all()))
        ) {
            throw new RuntimeException('Discord role criteria are unavailable.');
        }

        return array_values($keys);
    }

    public function records(): array
    {
        $definitions = $this->all();

        return array_map(function ($key) use ($definitions) {
            $field = $definitions[$key];
            $localized = fn ($label, $limit) => [
                'fr' => mb_substr(__($label, [], 'fr'), 0, $limit),
                'en-US' => mb_substr(__($label, [], 'en'), 0, $limit),
            ];
            $names = $localized($field['name'], 100);
            $descriptions = $localized($field['description'], 200);

            return [
                'key' => $key,
                'type' => $field['type'],
                'name' => $names['en-US'],
                'description' => $descriptions['en-US'],
                'name_localizations' => $names,
                'description_localizations' => $descriptions,
            ];
        }, $this->selected());
    }

    public function values(User $user): array
    {
        $definitions = $this->all();
        $roles = $user
            ->effectiveRoles()
            ->sort(fn ($left, $right) => $right->position <=> $left->position ?: $left->id <=> $right->id)
            ->values();
        $values = [];
        foreach ($this->selected() as $key) {
            $field = $definitions[$key];
            $value = $field['value']($user, $roles);
            if (!is_int($value) && !is_string($value) && !is_bool($value)) {
                throw new RuntimeException('Invalid Discord role criterion value.');
            }
            $value = is_bool($value) ? (string) (int) $value : (string) $value;
            if (
                mb_strlen($value) > 100 ||
                preg_match('/[\x00-\x1F\x7F]/', $value) ||
                (in_array($field['type'], [1, 2, 3, 4], true) && !preg_match('/^-?[0-9]{1,19}$/D', $value)) ||
                (in_array($field['type'], [7, 8], true) && !in_array($value, ['0', '1'], true))
            ) {
                throw new RuntimeException('Invalid Discord role criterion value.');
            }
            if (in_array($field['type'], [5, 6], true)) {
                CarbonImmutable::parse($value);
            }
            $values[$key] = $value;
        }

        return $values;
    }
}
