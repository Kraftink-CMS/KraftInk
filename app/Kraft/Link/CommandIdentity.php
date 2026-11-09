<?php

namespace App\Kraft\Link;

use App\Kraft\Games\Contracts\FiltersLinkCommandTargets;
use App\Kraft\Games\Contracts\GameAdapter;
use App\Kraft\Games\Contracts\ProvidesCommandIdentity;
use App\Kraft\Games\GameRegistry;
use App\Models\Accounts\User;
use App\Models\Servers\Server;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class CommandIdentity
{
    public function filterClaims(Builder $query, Server $server): void
    {
        $adapter = app(GameRegistry::class)->find($server->game);
        if ($adapter instanceof FiltersLinkCommandTargets) {
            $adapter->filterLinkCommandTargets($query, request()->header('X-KraftLink-Identity-Targets'));
        }
    }

    public function resolve(User $user, ?int $target, array $commands, bool $requiresOnline): array
    {
        $registry = app(GameRegistry::class);
        $game = $registry->activeId();
        $this->validateServer($target, $game, 'commands');
        $identity = $game ? $registry->identityFor($user, $game) : null;

        return [
            'server_id' => $target,
            'game' => $game,
            'requires_online' => $requiresOnline,
        ] + $this->identity($registry->find($game), $identity?->uuid, $identity?->username, $commands, $requiresOnline);
    }

    public function resolveGuest(?int $target, array $commands, bool $requiresOnline, array $input): array
    {
        $registry = app(GameRegistry::class);
        $game = $registry->activeId();
        $this->validateServer($target, $game, 'targets');
        if (!$game) {
            throw ValidationException::withMessages(['targets' => __('link.commands.invalid_server')]);
        }
        $name = $input['player_name'] ?? '';
        $uuid = $input['player_uuid'] ?? '';
        if (!is_string($name) || strlen($name) > 60 || !is_string($uuid)) {
            throw ValidationException::withMessages(['targets' => __('link.commands.invalid_target')]);
        }
        $adapter = $registry->find($game);
        if (!($adapter instanceof ProvidesCommandIdentity) && !preg_match('/^[a-zA-Z0-9_]{1,32}$/D', $name)) {
            throw ValidationException::withMessages(['targets' => __('link.commands.invalid_target')]);
        }

        return [
            'server_id' => $target,
            'game' => $game,
            'requires_online' => $requiresOnline,
        ] + $this->identity($adapter, $uuid, $name, $commands, $requiresOnline);
    }

    public function expand(object $snapshot, string $command): string
    {
        $values = !empty($snapshot->command_identity)
            ? json_decode($snapshot->command_identity, true, 16, JSON_THROW_ON_ERROR)
            : ['player' => $snapshot->player_name ?? '', 'uuid' => $snapshot->player_uuid ?? ''];
        $replacements = [];
        foreach ($values as $key => $value) {
            if (!$this->validCommandValue($value)) {
                throw ValidationException::withMessages(['commands' => __('link.commands.identity_required')]);
            }
            $token = '{'.$key.'}';
            if ($value !== '' && !preg_match('/^[a-zA-Z0-9_.:+-]+$/D', $value)) {
                $value = '"'.$value.'"';
                $replacements['"'.$token.'"'] = $value;
            }
            $replacements[$token] = $value;
        }

        return strtr($command, $replacements);
    }

    private function validateServer(?int $target, ?string $game, string $field): void
    {
        if ($target && !Server::query()->whereKey($target)->where('game', $game)->exists()) {
            throw ValidationException::withMessages([$field => __('link.commands.invalid_server')]);
        }
    }

    private function identity(?GameAdapter $adapter, ?string $uuid, ?string $name, array $commands, bool $requiresOnline): array
    {
        foreach ($commands as $command) {
            if (!is_string($command) || strlen($command) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $command)) {
                throw ValidationException::withMessages(['commands' => __('link.commands.invalid_command')]);
            }
        }
        $tokens =
            $adapter instanceof ProvidesCommandIdentity
                ? $adapter::COMMAND_TOKENS
                : ProvidesCommandIdentity::COMMAND_TOKENS;
        $pattern = '/\{('.implode('|', array_map(fn (string $token) => preg_quote($token, '/'), $tokens)).')\}/';
        $needsIdentity = $requiresOnline || preg_match($pattern, implode("\n", $commands));
        if ($adapter instanceof ProvidesCommandIdentity && ($uuid || $needsIdentity)) {
            $values = $adapter->commandIdentity($uuid ?? '', $name);
        } else {
            if ($uuid) {
                $uuid = $adapter
                    ? new PlayerTarget($adapter->normalizeUuid($uuid))->canonical()
                    : new PlayerTarget($uuid)->uuid();
                if (!$uuid) {
                    throw ValidationException::withMessages(['commands' => __('link.commands.identity_required')]);
                }
            }
            $name = $name ?: '';
            $player = strlen($name) <= 60 && $this->validCommandValue($name) ? $name : '';
            $values = ['player' => $player, 'uuid' => $uuid ?: ''];
        }
        foreach ($values as $key => $value) {
            if (
                !is_string($key) ||
                !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $key) ||
                !is_string($value) ||
                !$this->validCommandValue($value)
            ) {
                throw ValidationException::withMessages(['commands' => __('link.commands.identity_required')]);
            }
        }
        if (
            !isset($values['player'], $values['uuid']) ||
            strlen($values['player']) > 60 ||
            strlen($values['uuid']) > 64
        ) {
            throw ValidationException::withMessages(['commands' => __('link.commands.identity_required')]);
        }
        if ($requiresOnline && empty($values['uuid'])) {
            throw ValidationException::withMessages(['commands' => __('link.commands.identity_required')]);
        }
        foreach ($commands as $command) {
            preg_match_all($pattern, $command, $matches);
            foreach ($matches[1] as $token) {
                if (($values[$token] ?? '') === '') {
                    throw ValidationException::withMessages(['commands' => __('link.commands.identity_required')]);
                }
            }
        }

        return [
            'player_name' => $values['player'] ?: null,
            'player_uuid' => $values['uuid'] ?: null,
            'command_identity' => json_encode($values, JSON_THROW_ON_ERROR),
        ];
    }

    private function validCommandValue(string $value): bool
    {
        return $value === '' || (strlen($value) <= 128 && preg_match(
            '/^[\p{L}\p{M}\p{N}_.:+-]+(?: [\p{L}\p{M}\p{N}_.:+-]+)*(?:#[0-9]{1,5})?$/uD',
            $value,
        ) === 1);
    }
}
