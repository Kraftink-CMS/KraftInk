<?php

namespace KraftInk\Games\Minecraft\Services\Auth;

use App\Kraft\Games\GameRegistry;
use App\Models\Accounts\User;
use App\Models\Servers\Server;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use KraftInk\Games\Minecraft\Services\Profiles\FloodgateProfiles;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BedrockChallenges
{
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const int LIFETIME = 300;

    public const string SOURCE = 'floodgate_session';

    public function __construct(
        private readonly BedrockVerificationPolicy $policy,
        private readonly GameRegistry $games,
        private readonly FloodgateProfiles $profiles,
    ) {
    }

    public function issue(User $user, string $nonce, ?int $loginExpiresAt = null): array
    {
        $timestamp = now()->timestamp;
        $expiresAt = min($timestamp + self::LIFETIME, $loginExpiresAt ?? PHP_INT_MAX);
        if (!$this->policy->available() || !$user->canAuthenticateAsMember() || $expiresAt <= $timestamp) {
            throw new HttpException(403);
        }
        $data = [
            'user' => $user->id,
            'version' => (int) $user->auth_session_version,
            'nonce' => hash('sha256', $nonce),
            'prefix' => $this->profiles->prefix(),
            'expires_at' => $expiresAt,
        ];
        $store = $this->store();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $code = '';
            foreach (str_split(random_bytes(10)) as $byte) {
                $code .= self::ALPHABET[ord($byte) & 31];
            }
            if ($store->add($this->key($code), $data, $expiresAt - $timestamp)) {
                return ['code' => $code, 'expires_at' => $expiresAt];
            }
        }

        throw new HttpException(503);
    }

    public function status(User $user, string $nonce, string $code): ?array
    {
        $data = $this->owned($this->key($code), $user, $nonce);

        return $data ? ['proof' => $data['proof'] ?? null] : null;
    }

    public function cancel(User $user, string $nonce, string $code): void
    {
        $key = $this->key($code);
        $store = $this->store();
        $store->lock($key.'.lock', 15)->block(3, function () use ($key, $store, $user, $nonce): void {
            $data = $store->get($key);
            if ($this->belongsTo($data, $user, $nonce)) {
                $store->forget($key);
            }
        });
    }

    public function redeem(Server $server, string $code, int $xuid, string $username): bool
    {
        $current = $this->policy->enabled() ? $this->policy->linkedServer($server->id) : null;
        if (!$current || !hash_equals($current->token_hash, $server->token_hash ?? '')) {
            throw new HttpException(403);
        }
        $uuid = str_pad(dechex($xuid), 32, '0', STR_PAD_LEFT);
        if (!$this->profiles->validName($username) || !$this->profiles->isFloodgateUuid($uuid)) {
            throw new HttpException(422);
        }
        $key = $this->key($code);
        $store = $this->store();

        return $store->lock($key.'.lock', 15)->block(3, function () use ($key, $store, $current, $uuid, $username): bool {
            $data = $store->get($key);
            $seconds = $this->remaining($data);
            if ($seconds <= 0) {
                return false;
            }
            $proof = ['uuid' => $uuid, 'username' => $username];
            $connector = ['id' => $current->id, 'stamp' => $this->connectorStamp($current)];
            if (isset($data['proof'])) {
                return $data['proof'] === $proof && $data['connector'] === $connector;
            }
            $data['proof'] = $proof;
            $data['connector'] = $connector;

            return $store->put($key, $data, $seconds);
        });
    }

    public function confirm(User $user, string $nonce, string $code): bool
    {
        $key = $this->key($code);
        $store = $this->store();

        return $store->lock($key.'.lock', 30)->block(3, function () use ($key, $store, $user, $nonce): bool {
            $confirmed = DB::transaction(function () use ($key, $user, $nonce): bool {
                $current = User::query()->whereKey($user->id)->lockForUpdate()->first();
                $data = $current ? $this->owned($key, $current, $nonce, lockConnector: true) : null;
                $proof = $data['proof'] ?? null;
                if ($proof === null) {
                    return false;
                }
                $identity = $this->games->identityFor($current, 'minecraft');
                if ($identity?->verified_at) {
                    if ($identity->uuid !== $proof['uuid'] || ($identity->metadata['source'] ?? null) !== self::SOURCE) {
                        return false;
                    }
                } else {
                    $this->games->verifyIdentity($current, 'minecraft', $proof, self::SOURCE);
                }

                return true;
            }, 3);
            if ($confirmed) {
                $store->forget($key);
            }

            return $confirmed;
        });
    }

    private function owned(string $key, User $user, string $nonce, bool $lockConnector = false): ?array
    {
        $data = $this->store()->get($key);

        if (
            !$this->policy->enabled() ||
            $this->remaining($data) <= 0 ||
            !$this->belongsTo($data, $user, $nonce) ||
            !$user->canAuthenticateAsMember() ||
            $data['version'] !== (int) $user->auth_session_version
        ) {
            return null;
        }
        if (isset($data['proof'])) {
            $connector = $data['connector'];
            $server = $this->policy->linkedServer($connector['id'], $lockConnector);
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

    private function remaining(?array $data): int
    {
        return ($data['prefix'] ?? null) === $this->profiles->prefix()
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

    private function key(string $code): string
    {
        return 'minecraft.bedrock-challenge.'.hash('sha256', strtoupper($code));
    }
}
