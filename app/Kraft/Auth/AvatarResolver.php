<?php

namespace App\Kraft\Auth;

use App\Kraft\Auth\Avatars\AvatarIdentity;
use App\Kraft\Auth\Avatars\AvatarProvider;
use App\Kraft\Games\GameRegistry;
use App\Models\Accounts\GameIdentity;
use App\Models\Accounts\User;
use Throwable;

class AvatarResolver
{
    private array $identities = [];

    public function __construct(private readonly GameRegistry $games, private readonly AvatarPolicy $policy)
    {
    }

    public function forUser(User $user): ?string
    {
        $game = $this->games->activeId();
        if (
            $user->anonymized_at ||
            !$game ||
            !config('games.adapters.'.$game) ||
            !($provider = $this->policy->provider($game)) ||
            ($user->avatar_path && $this->policy->allowsUploads())
        ) {
            return null;
        }
        if ($user->exists && !array_key_exists($user->id, $this->identities[$game] ?? [])) {
            $this->prepare([$user]);
        }
        $identity = $this->identities[$game][$user->id] ?? null;
        if (
            $identity &&
            ($url = $this->resolve($provider, new AvatarIdentity($game, $identity->uuid, $identity->username)))
        ) {
            return $url;
        }

        return $this->resolve($provider, new AvatarIdentity($game, null, $user->username));
    }

    public function forIdentity(GameIdentity $identity): ?string
    {
        $provider = $this->policy->provider($identity->game);

        return $provider
            ? $this->resolve($provider, new AvatarIdentity($identity->game, $identity->uuid, $identity->username))
            : null;
    }

    public function prepare(iterable $users): void
    {
        $game = $this->games->activeId();
        if (!$game || !config('games.adapters.'.$game) || !$this->policy->provider($game)) {
            return;
        }
        $uploads = $this->policy->allowsUploads();
        $ids = [];
        foreach ($users as $user) {
            if (
                $user instanceof User &&
                $user->exists &&
                !$user->anonymized_at &&
                (!$user->avatar_path || !$uploads) &&
                !array_key_exists($user->id, $this->identities[$game] ?? [])
            ) {
                $ids[] = $user->id;
            }
        }
        if ($ids === []) {
            return;
        }
        $identities = GameIdentity::query()
            ->where('game', $game)
            ->whereIn('user_id', array_unique($ids))
            ->get()
            ->keyBy('user_id');
        foreach ($ids as $id) {
            $this->identities[$game][$id] = $identities->get($id);
        }
    }

    public function forget(string $game, int $user): void
    {
        unset($this->identities[$game][$user]);
    }

    private function resolve(AvatarProvider $provider, AvatarIdentity $identity): ?string
    {
        try {
            $url = $provider->url($identity);
        } catch (Throwable) {
            return null;
        }
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($url);

        return ($parts['scheme'] ?? '') === 'https' && !isset($parts['user']) && !isset($parts['pass']) ? $url : null;
    }
}
