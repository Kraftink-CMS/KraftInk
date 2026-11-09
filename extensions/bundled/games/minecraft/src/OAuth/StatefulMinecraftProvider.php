<?php

namespace KraftInk\Games\Minecraft\OAuth;

use GuzzleHttp\RequestOptions;
use SocialiteProviders\Minecraft\Provider;

class StatefulMinecraftProvider extends Provider
{
    protected function isStateless(): bool
    {
        return false;
    }

    protected function getMicrosoftToken($code): array
    {
        $fields = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUrl,
            'code_verifier' => $this->request->session()->pull('code_verifier'),
        ];
        $response = $this->getHttpClient()->post('https://login.live.com/oauth20_token.srf', [
            RequestOptions::FORM_PARAMS => $fields,
            RequestOptions::HEADERS => ['Accept' => 'application/json'],
        ]);
        $data = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);

        return ['token' => $data['access_token']];
    }
}
