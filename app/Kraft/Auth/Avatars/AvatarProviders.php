<?php

namespace App\Kraft\Auth\Avatars;

use InvalidArgumentException;
use LogicException;
use Throwable;

class AvatarProviders
{
    public function register(string $game, string $id, string $class, bool $default = false): void
    {
        if (!is_subclass_of($class, AvatarProvider::class)) {
            throw new InvalidArgumentException('Invalid avatar provider registration.');
        }
        $this->registerDefinition($game, $id, $class, $default);
    }

    public function registerUrl(
        string $game,
        string $id,
        array $metadata,
        string $template,
        ?string $usernamePattern = null,
        bool $default = false,
    ): void {
        new UrlAvatarProvider($game, $id, $metadata, $template, $usernamePattern);
        $this->registerDefinition(
            $game,
            $id,
            [
                'metadata' => $metadata,
                'template' => $template,
                'username_pattern' => $usernamePattern,
            ],
            $default,
        );
    }

    private function registerDefinition(string $game, string $id, string|array $definition, bool $default): void
    {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $game) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $id)) {
            throw new InvalidArgumentException('Invalid avatar provider registration.');
        }
        $existing = config('avatars.providers.'.$game.'.'.$id);
        if ($existing !== null && $existing !== $definition) {
            throw new LogicException('An avatar provider identifier is already registered for this game.');
        }
        if ($default) {
            $previous = config('avatars.defaults.'.$game);
            if ($previous !== null && $previous !== $id) {
                throw new LogicException('A default avatar provider is already registered for this game.');
            }
        }
        config(['avatars.providers.'.$game.'.'.$id => $definition]);
        if ($default) {
            config(['avatars.defaults.'.$game => $id]);
        }
    }

    public function forGame(string $game): array
    {
        $providers = [];
        foreach ((array) config('avatars.providers.'.$game, []) as $id => $definition) {
            if (!is_string($id)) {
                continue;
            }
            try {
                if (is_array($definition)) {
                    $provider = new UrlAvatarProvider(
                        $game,
                        $id,
                        $definition['metadata'] ?? [],
                        $definition['template'] ?? '',
                        $definition['username_pattern'] ?? null,
                    );
                } elseif (is_string($definition) && is_subclass_of($definition, AvatarProvider::class)) {
                    $provider = app($definition);
                } else {
                    continue;
                }
                $metadata = $provider->metadata();
                if (
                    $provider->id() !== $id ||
                    !is_string($metadata['name'] ?? null) ||
                    trim($metadata['name']) === '' ||
                    !is_string($metadata['description'] ?? null) ||
                    (isset($metadata['website']) && !is_string($metadata['website']))
                ) {
                    continue;
                }
                $providers[$id] = $provider;
            } catch (Throwable) {
                continue;
            }
        }

        return $providers;
    }

    public function get(string $game, string $id): ?AvatarProvider
    {
        return $this->forGame($game)[$id] ?? null;
    }

    public function defaultId(string $game): ?string
    {
        $id = config('avatars.defaults.'.$game);

        return is_string($id) && $id !== '' ? $id : null;
    }
}
