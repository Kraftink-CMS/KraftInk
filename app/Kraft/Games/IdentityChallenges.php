<?php

namespace App\Kraft\Games;

use App\Kraft\Games\Contracts\GameIdentityProof;
use App\Models\Accounts\User;
use App\Models\Servers\Server;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class IdentityChallenges
{
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const int LIFETIME = 300;

    public function __construct(private readonly GameRegistry $games)
    {
    }

    public function issue(GameIdentityProof $verification, User $user, string $nonce, ?int $expiresAt = null): array
    {
        $timestamp = now()->timestamp;
        $expiresAt = min($timestamp + self::LIFETIME, $expiresAt ?? PHP_INT_MAX);
        $configuration = $verification->configuration();
        abort_if($configuration === null || !$user->canAuthenticateAsMember() || $expiresAt <= $timestamp, 403);

        $data = [
            'user' => $user->id,
            'version' => (int) $user->auth_session_version,
            'nonce' => hash('sha256', $nonce),
            'configuration' => $configuration,
            'expires_at' => $expiresAt,
        ];
        $store = $this->store();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $code = '';
            foreach (str_split(random_bytes(10)) as $byte) {
                $code .= self::ALPHABET[ord($byte) & 31];
            }
            if ($store->add($this->key($verification, $code), $data, $expiresAt - $timestamp)) {
                return ['code' => $code, 'expires_at' => $expiresAt];
            }
        }

        abort(503);
    }

    public function status(GameIdentityProof $verification, User $user, string $nonce, string $code): ?array
    {
        $data = $this->owned($verification, $this->key($verification, $code), $user, $nonce);

        return $data === null ? null : ['proof' => $data['proof'] ?? null];
    }

    public function cancel(GameIdentityProof $verification, User $user, string $nonce, string $code): void
    {
        $key = $this->key($verification, $code);
        $store = $this->store();
        $store->lock($key.'.lock', 15)->block(3, function () use ($key, $store, $user, $nonce): void {
            if ($this->belongsTo($store->get($key), $user, $nonce)) {
                $store->forget($key);
            }
        });
    }

    public function redeem(GameIdentityProof $verification, Server $server, string $code, array $proof): bool
    {
        abort_unless($verification->game() === $server->game && $verification->configuration() !== null, 403);
        $current = $verification->linkedServer($server->id);
        abort_unless($current && hash_equals($current->token_hash, $server->token_hash ?? ''), 403);

        $key = $this->key($verification, $code);
        $store = $this->store();

        return $store->lock($key.'.lock', 15)->block(3, function () use ($verification, $key, $store, $current, $proof): bool {
            $data = $store->get($key);
            $seconds = $this->remaining($verification, $data);
            if ($seconds <= 0) {
                return false;
            }
            $connector = ['id' => $current->id, 'stamp' => $this->connectorStamp($current)];
            if (isset($data['proof'])) {
                return $data['proof'] === $proof && $data['connector'] === $connector;
            }
            $data['proof'] = $proof;
            $data['connector'] = $connector;

            return $store->put($key, $data, $seconds);
        });
    }

    public function confirm(GameIdentityProof $verification, User $user, string $nonce, string $code): bool
    {
        $key = $this->key($verification, $code);
        $store = $this->store();

        return $store->lock($key.'.lock', 30)->block(3, function () use ($verification, $key, $store, $user, $nonce): bool {
            $confirmed = DB::transaction(function () use ($verification, $key, $user, $nonce): bool {
                $current = User::query()->whereKey($user->id)->lockForUpdate()->first();
                $data = $current ? $this->owned($verification, $key, $current, $nonce, lockConnector: true) : null;
                $proof = $data['proof'] ?? null;
                if ($proof === null) {
                    return false;
                }
                $game = $verification->game();
                $source = $verification->source();
                $identity = $this->games->identityFor($current, $game);
                if ($identity?->verified_at && ($identity->uuid !== $proof['uuid'] || ($identity->metadata['source'] ?? null) !== $source)) {
                    return false;
                }
                if (!$identity?->verified_at || $identity->username !== $proof['username']) {
                    $this->games->verifyIdentity($current, $game, $proof, $source);
                }

                return true;
            }, 3);
            if ($confirmed) {
                $store->forget($key);
            }

            return $confirmed;
        });
    }

    private function owned(GameIdentityProof $verification, string $key, User $user, string $nonce, bool $lockConnector = false): ?array
    {
        $data = $this->store()->get($key);
        if (
            $this->remaining($verification, $data) <= 0 ||
            !$this->belongsTo($data, $user, $nonce) ||
            !$user->canAuthenticateAsMember() ||
            $data['version'] !== (int) $user->auth_session_version
        ) {
            return null;
        }
        if (isset($data['proof'])) {
            $connector = $data['connector'];
            $server = $verification->linkedServer($connector['id'], $lockConnector);
            if (!$server || !hash_equals($connector['stamp'], $this->connectorStamp($server))) {
                return null;
            }
        }

        return $data;
    }

    private function belongsTo(?array $data, User $user, string $nonce): bool
    {
        return $data && $data['user'] === $user->id && hash_equals($data['nonce'], hash('sha256', $nonce));
    }

    private function remaining(GameIdentityProof $verification, ?array $data): int
    {
        $configuration = $verification->configuration();

        return $configuration !== null && $configuration === ($data['configuration'] ?? null)
            ? max(0, $data['expires_at'] - now()->timestamp)
            : 0;
    }

    private function connectorStamp(Server $server): string
    {
        return hash('sha256', $server->id.'|'.$server->token_hash);
    }

    private function store(): Repository
    {
        return Cache::store('database');
    }

    private function key(GameIdentityProof $verification, string $code): string
    {
        return 'game.identity-challenge.'.$verification->game().'.'.hash('sha256', strtoupper($code));
    }
}
