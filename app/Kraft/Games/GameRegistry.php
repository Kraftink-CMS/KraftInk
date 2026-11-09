<?php

namespace App\Kraft\Games;

use App\Kraft\Games\Contracts\GameAdapter;
use App\Kraft\Auth\AvatarResolver;
use App\Kraft\Games\Contracts\PublicProfileAvailability;
use App\Kraft\Games\Contracts\ResolvesPublicProfiles;
use App\Kraft\Games\Contracts\ValidatesDeclaredIdentity;
use App\Kraft\Settings;
use App\Models\Accounts\GameIdentity;
use App\Models\Accounts\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class GameRegistry
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function all(): array
    {
        $games = [];
        foreach (array_keys(config('games.adapters', [])) as $id) {
            $games[$id] = $this->definition($id, $this->get($id));
        }

        return $games;
    }

    public function linkableGames(): array
    {
        return array_filter($this->active(), fn ($game) => $game['connections'] !== []);
    }

    public function supportsPublicProfiles(string $game): bool
    {
        return $this->publicProfileAdapter($game) !== null;
    }

    public function publicProfile(string $game, string $username): GameProfile
    {
        $adapter = $this->publicProfileAdapter($game);
        if ($adapter === null) {
            throw ValidationException::withMessages(['username' => __('site.games.profile_lookup_unavailable')]);
        }
        $profile = $adapter->publicProfile($username);

        $uuid = $adapter->normalizeUuid($profile->uuid);
        $identity = new GameIdentity(['game' => $game, 'uuid' => $uuid, 'username' => $profile->username]);

        return new GameProfile($uuid, $profile->username, app(AvatarResolver::class)->forIdentity($identity));
    }

    public function activeId(): ?string
    {
        return $this->settings->get('games.default', array_key_first(config('games.adapters', [])));
    }

    public function active(): array
    {
        $id = $this->activeId();
        $adapter = $this->find($id);

        return $id !== null && $adapter !== null ? [$id => $this->definition($id, $adapter)] : [];
    }

    public function accountGames(): array
    {
        return array_filter($this->active(), fn ($game) => $game['account_profiles']);
    }

    public function get(string $id): GameAdapter
    {
        return $this->find($id) ?? throw new RuntimeException('Unsupported game adapter.');
    }

    public function find(?string $id): ?GameAdapter
    {
        $adapters = config('games.adapters', []);
        if ($id === null || $id === '' || !array_key_exists($id, $adapters)) {
            return null;
        }
        $class = $adapters[$id];
        if (!is_string($class) || !is_subclass_of($class, GameAdapter::class)) {
            throw new RuntimeException('Unsupported game adapter.');
        }
        $adapter = app($class);
        if (!$adapter instanceof GameAdapter) {
            throw new RuntimeException('Unsupported game adapter.');
        }
        if ($adapter->id() !== $id) {
            throw new RuntimeException('Game adapter identifier mismatch.');
        }

        return $adapter;
    }

    private function definition(string $id, GameAdapter $adapter): array
    {
        return $adapter->metadata() + [
            'id' => $id,
            'settings' => $adapter->settingsSchema(),
            'connections' => [],
            'default_port' => null,
            'account_profiles' => true,
        ];
    }

    public function identityFor(User $user, string $game): ?GameIdentity
    {
        return GameIdentity::query()->where('user_id', $user->id)->where('game', $game)->first();
    }

    public function assertUserCanAuthenticate(User $user): void
    {
        $game = $this->activeId();
        $this->find($game)?->assertCanAuthenticate($user, $this->identityFor($user, $game), $this->settings);
    }

    public function declareIdentity(
        User $user,
        string $game,
        array $data,
        array $metadata = ['source' => 'self_declared'],
    ): GameIdentity {
        $adapter = $this->get($game);
        if ($game !== $this->activeId()) {
            throw ValidationException::withMessages(['game' => __('site.games.game_unavailable')]);
        }
        $existing = $this->identityFor($user, $game);
        if ($existing?->verified_at) {
            throw ValidationException::withMessages(['uuid' => __('site.games.verified_identity_protected')]);
        }
        if ($adapter instanceof ValidatesDeclaredIdentity) {
            $data = $adapter->declaredIdentity($data);
        }

        return $this->saveIdentity(
            $user,
            $game,
            $adapter->normalizeUuid($data['uuid']),
            $data['username'],
            $metadata,
            false,
        );
    }

    public function verifyIdentity(
        User $user,
        string $game,
        array $data,
        string $source,
        bool $replaceUnverified = true,
        array $metadata = [],
    ): GameIdentity {
        return $this->saveIdentity(
            $user,
            $game,
            $this->get($game)->normalizeUuid($data['uuid']),
            $data['username'],
            ['source' => $source] + $metadata,
            true,
            $replaceUnverified,
        );
    }

    private function saveIdentity(
        User $user,
        string $game,
        string $uuid,
        string $username,
        array $metadata,
        bool $verified,
        bool $replaceUnverified = true,
    ): GameIdentity {
        $field = $verified ? 'oauth' : 'uuid';
        try {
            [$identity, $previousOwner] = DB::transaction(function () use (
                $user,
                $game,
                $uuid,
                $username,
                $metadata,
                $verified,
                $field,
                $replaceUnverified,
            ): array {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $current = $user->gameIdentities()->where('game', $game)->lockForUpdate()->first();
                if (!$verified && $current?->verified_at) {
                    throw ValidationException::withMessages(['uuid' => __('site.games.verified_identity_protected')]);
                }
                $other = GameIdentity::query()
                    ->where('game', $game)
                    ->where('uuid', $uuid)
                    ->where('user_id', '!=', $user->id)
                    ->lockForUpdate()
                    ->first();
                if ($other && (!$verified || !$replaceUnverified || $other->verified_at)) {
                    throw ValidationException::withMessages([$field => __('site.games.identity_already_linked')]);
                }
                $other?->delete();
                $identity = $current ?? $user->gameIdentities()->make(['game' => $game]);
                $identity->fill([
                    'uuid' => $uuid,
                    'username' => $username,
                    'verified_at' => $verified ? now() : null,
                    'metadata' => $metadata,
                ])->save();

                return [$identity, $other?->user_id];
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([$field => __('site.games.identity_already_linked')]);
        }
        $avatars = app(AvatarResolver::class);
        $avatars->forget($game, $user->id);
        if ($previousOwner !== null) {
            $avatars->forget($game, $previousOwner);
        }

        return $identity;
    }

    private function publicProfileAdapter(string $game): (GameAdapter&ResolvesPublicProfiles)|null
    {
        $adapter = $this->find($game);
        if (
            !$adapter instanceof ResolvesPublicProfiles ||
            ($adapter instanceof PublicProfileAvailability && !$adapter->publicProfilesAvailable())
        ) {
            return null;
        }

        return $adapter;
    }
}
