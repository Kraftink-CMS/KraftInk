<?php

namespace App\Kraft\Auth;

use App\Kraft\Auth\Contracts\CaptchaProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Illuminate\Http\Client\ConnectionException;

class RemoteCaptchaProvider implements CaptchaProvider
{
    public function __construct(private array $configuration)
    {
    }

    /**
     * @throws ConnectionException
     */
    public function verify(Request $request, string $token, string $secret, string $siteKey, string $action): bool
    {
        $payload = ['secret' => $secret, 'response' => $token, 'remoteip' => $request->ip()];
        if ($this->configuration['send_sitekey'] ?? false) {
            $payload['sitekey'] = $siteKey;
        }
        $response = Http::asForm()
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(8)
            ->post($this->configuration['url'], $payload);
        if (!$response->successful()) {
            throw new RuntimeException();
        }
        $result = $response->json();
        $hostname = strtolower((string) parse_url(config('app.url'), PHP_URL_HOST));

        return is_array($result) &&
            ($result['success'] ?? false) === true &&
            is_string($result['hostname'] ?? null) &&
            hash_equals($hostname, strtolower($result['hostname'])) &&
            (!($this->configuration['match_action'] ?? false) || ($result['action'] ?? null) === $action);
    }
}
