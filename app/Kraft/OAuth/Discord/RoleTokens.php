<?php

namespace App\Kraft\OAuth\Discord;

use App\Exceptions\RoleApiFailure;
use App\Kraft\OAuth\OAuthRegistry;
use App\Kraft\Secrets;
use App\Kraft\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Throwable;
use Illuminate\Http\Client\ConnectionException;

class RoleTokens
{
    public function currentClient(string $provider): array
    {
        /** @var Settings $settings */
        $settings = app(Settings::class);
        /** @var Secrets $secrets */
        $secrets = app(Secrets::class);

        return [
            'client_id' => (string) $settings->get('oauth.'.$provider.'.client_id', ''),
            'client_secret' => $secrets->get('oauth.'.$provider.'.client_secret'),
        ];
    }

    public function client(string $provider, array $snapshot): array
    {
        if (array_key_exists($provider, app(OAuthRegistry::class)->definitions())) {
            try {
                $current = $this->currentClient($provider);
                if ($current['client_id'] === $snapshot['client_id'] && $current['client_secret'] !== '') {
                    return $current;
                }
            } catch (Throwable) {
            }
        }

        return $snapshot;
    }

    /**
     * @throws ConnectionException
     */
    public function valid(array $credentials, string $provider, bool $force = false): array
    {
        if (!$force && CarbonImmutable::parse($credentials['expires_at'])->isAfter(now()->addSeconds(30))) {
            return $credentials;
        }
        if (empty($credentials['refresh_token'])) {
            throw new RoleApiFailure('authorization_expired', requiresAuthorization: true);
        }
        $client = $this->client($provider, $credentials['client_credentials']);
        $response = app(DiscordRoleApi::class)->refresh($client, $credentials['refresh_token']);
        if (
            $response->status() === 400 &&
            $response->json('error') === 'invalid_client' &&
            $client !== $credentials['client_credentials']
        ) {
            $client = $credentials['client_credentials'];
            $response = app(DiscordRoleApi::class)->refresh($client, $credentials['refresh_token']);
        }
        if ($response->status() === 400 && $response->json('error') === 'invalid_grant') {
            throw new RoleApiFailure('authorization_expired', requiresAuthorization: true);
        }
        $this->ensure($response);
        $data = $response->json();
        if (!is_array($data) || (isset($data['scope']) && !is_string($data['scope']))) {
            throw new RoleApiFailure('authorization_expired', requiresAuthorization: true);
        }
        $scopes = isset($data['scope']) ? explode(' ', (string) $data['scope']) : ['role_connections.write'];
        if (
            !is_string($data['access_token'] ?? null) ||
            $data['access_token'] === '' ||
            strlen($data['access_token']) > 8192 ||
            !is_numeric($data['expires_in'] ?? null) ||
            (int) $data['expires_in'] < 1 ||
            (int) $data['expires_in'] > 5_184_000 ||
            !in_array('role_connections.write', $scopes, true)
        ) {
            throw new RoleApiFailure('authorization_expired', requiresAuthorization: true);
        }
        $refresh = $data['refresh_token'] ?? $credentials['refresh_token'];
        if (
            !is_string($refresh) ||
            strlen($refresh) > 8192 ||
            preg_match('/[\x00-\x1F\x7F]/', $data['access_token'].$refresh)
        ) {
            throw new RoleApiFailure('authorization_expired', requiresAuthorization: true);
        }

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $refresh,
            'expires_at' => now()->addSeconds((int) $data['expires_in'])->toIso8601String(),
            'client_credentials' => $client,
        ];
    }

    public function ensure(Response $response): void
    {
        if ($response->successful()) {
            return;
        }
        if ($response->status() === 429) {
            $delay = $response->json('retry_after') ?? $response->header('Retry-After');
            throw new RoleApiFailure(
                'rate_limited',
                max(1, min(86400, is_numeric($delay) ? (int) ceil((float) $delay) : 60)),
            );
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw new RoleApiFailure('authorization_expired', requiresAuthorization: true);
        }
        throw new RoleApiFailure($response->status() >= 500 ? 'unavailable' : 'configuration_error', 300);
    }
}
