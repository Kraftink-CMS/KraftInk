<?php

namespace App\Kraft\OAuth\Discord;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Illuminate\Http\Client\ConnectionException;

class DiscordRoleApi
{
    /**
     * @throws ConnectionException
     */
    public function register(string $application, string $botToken, array $records): Response
    {
        $this->application($application);

        return $this->request()
            ->withHeaders(['Authorization' => 'Bot '.$botToken])
            ->put('https://discord.com/api/v10/applications/'.$application.'/role-connections/metadata', $records);
    }

    /**
     * @throws ConnectionException
     */
    public function update(string $application, string $accessToken, array $data): Response
    {
        $this->application($application);

        return $this->request()
            ->withToken($accessToken)
            ->put('https://discord.com/api/v10/users/@me/applications/'.$application.'/role-connection', $data);
    }

    /**
     * @throws ConnectionException
     */
    public function remove(string $application, string $accessToken): Response
    {
        $this->application($application);

        return $this->request()
            ->withToken($accessToken)
            ->delete('https://discord.com/api/v10/users/@me/applications/'.$application.'/role-connection');
    }

    /**
     * @throws ConnectionException
     */
    public function refresh(array $client, string $refreshToken): Response
    {
        return $this->request()
            ->asForm()
            ->post('https://discord.com/api/oauth2/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $client['client_id'],
                'client_secret' => $client['client_secret'],
            ]);
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->timeout(15)->connectTimeout(5)->withoutRedirecting();
    }

    private function application(string $application): void
    {
        if (!preg_match('/^[0-9]{5,32}$/D', $application)) {
            throw new InvalidArgumentException('Invalid Discord application identifier.');
        }
    }
}
