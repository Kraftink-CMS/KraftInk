<?php

namespace App\Kraft\Link;

use App\Models\Servers\Server;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ConnectorInstance
{
    private const int IDLE_SECONDS = 7_200;

    private const int REFRESH_SECONDS = 60;

    public function confirm(Request $request, Server $server): void
    {
        $instance = $request->header('X-KraftLink-Instance');
        $platform = $request->header('X-KraftLink-Platform');
        $supported = config('games.link_platforms.'.$server->game);
        if ($supported !== null) {
            abort_unless(in_array($platform, $supported, true), 422);
        }
        if ($platform !== null) {
            abort_unless(in_array($platform, ['bukkit', 'velocity', 'hytale', 'fabric', 'forge', 'neoforge', 'sponge', 'nukkit'], true), 422);
            $request->attributes->set('link.connector_platform', $platform);
        }
        if ($instance === null) {
            return;
        }
        abort_unless(is_string($instance) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $instance) === 1, 422);
        $instance = strtolower($instance);
        $key = 'link.instance.'.$server->id;
        $tokenHash = $server->token_hash;
        $cache = Cache::store('database');
        $binding = $this->binding($cache, $key, $instance, $tokenHash, $platform);
        if ($this->isFresh($binding, $platform)) {
            return;
        }

        try {
            $cache->lock($key.'.lock', 30)->block(2, function () use ($cache, $key, $instance, $server, $tokenHash, $platform): void {
                $binding = $this->binding($cache, $key, $instance, $tokenHash, $platform);
                if ($this->isFresh($binding, $platform)) {
                    return;
                }
                abort_unless(Server::linkedKraftLink()->whereKey($server->id)->where('token_hash', $tokenHash)->exists(), 401);
                $binding = [
                    'instance' => $instance,
                    'token_hash' => $tokenHash,
                    'platform' => $platform ?? $binding['platform'] ?? null,
                    'updated_at' => now()->timestamp,
                ];
                abort_unless($cache->put($key, $binding, self::IDLE_SECONDS), 503);
            });
        } catch (LockTimeoutException) {
            throw new HttpException(503);
        }
    }

    public function forget(Server $server): void
    {
        Cache::store('database')->forget('link.instance.'.$server->id);
    }

    private function binding(Repository $cache, string $key, string $instance, string $tokenHash, ?string $platform): ?array
    {
        $binding = $cache->get($key);
        if (!is_array($binding) || $binding['token_hash'] !== $tokenHash) {
            return null;
        }
        $boundPlatform = $binding['platform'] ?? null;
        abort_if($binding['instance'] !== $instance || ($platform !== null && $boundPlatform !== null && $boundPlatform !== $platform),
            409, 'This KraftLink token is in use by another installation.');

        return $binding;
    }

    private function isFresh(?array $binding, ?string $platform): bool
    {
        return $binding !== null && ($platform === null || isset($binding['platform'])) &&
            $binding['updated_at'] > now()->timestamp - self::REFRESH_SECONDS;
    }
}
