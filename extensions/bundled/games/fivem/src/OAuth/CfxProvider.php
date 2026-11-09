<?php

namespace KraftInk\Games\FiveM\OAuth;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Two\User;
use phpseclib4\Crypt\RSA;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse;

class CfxProvider implements Provider
{
    private const string BASE_URL = 'https://forum.cfx.re';

    private const string SESSION_KEY = 'oauth.cfx.authorization';

    public function __construct(private readonly string $callback)
    {
    }

    public function redirect(): RedirectResponse
    {
        if (!str_starts_with($this->callback, 'https://')) {
            throw new RuntimeException('Cfx.re authorization requires an HTTPS callback.');
        }
        $key = RSA::createKey(2048);
        $nonce = bin2hex(random_bytes(24));
        $client = hash_hmac('sha256', 'cfx:'.$this->callback, (string) config('app.key'));
        request()->session()->put(self::SESSION_KEY, [
            'nonce' => $nonce,
            'private' => Crypt::encryptString($key->toString('PKCS8')),
            'callback' => $this->callback,
            'expires' => now()->addMinutes(5)->getTimestamp(),
        ]);

        return new RedirectResponse(self::BASE_URL.'/user-api-key/new?'.http_build_query([
            'auth_redirect' => $this->callback,
            'application_name' => 'KraftInk',
            'client_id' => $client,
            'nonce' => $nonce,
            'public_key' => $key->getPublicKey()->toString('PKCS8'),
            'padding' => 'oaep',
            'scopes' => 'session_info',
        ], '', '&', PHP_QUERY_RFC3986));
    }

    public function user(): User
    {
        $authorization = request()->session()->pull(self::SESSION_KEY);
        $payload = request()->query('payload');
        if (
            !is_array($authorization) || $authorization['expires'] <= now()->getTimestamp() ||
            $authorization['callback'] !== $this->callback || !is_string($payload) || strlen($payload) > 512
        ) {
            throw new RuntimeException('Invalid Cfx.re authorization.');
        }
        $encrypted = base64_decode($payload, true);
        if (
            $encrypted === false || strlen($encrypted) !== 256 ||
            !openssl_private_decrypt($encrypted, $plain, Crypt::decryptString($authorization['private']), OPENSSL_PKCS1_OAEP_PADDING)
        ) {
            throw new RuntimeException('Invalid Cfx.re authorization.');
        }
        $proof = json_decode($plain, true, 4, JSON_THROW_ON_ERROR);
        if (
            !is_array($proof) || !is_string($proof['nonce'] ?? null) ||
            !hash_equals($authorization['nonce'], $proof['nonce']) || !is_string($proof['key'] ?? null) ||
            !preg_match('/^[A-Za-z0-9_-]{16,512}$/D', $proof['key'])
        ) {
            throw new RuntimeException('Invalid Cfx.re authorization.');
        }

        $response = Http::acceptJson()->connectTimeout(5)->timeout(10)->withoutRedirecting()
            ->withHeaders(['User-Api-Key' => $proof['key']])
            ->get(self::BASE_URL.'/session/current.json');
        if (!$response->ok() || strlen($response->body()) > 65536) {
            throw new RuntimeException('Cfx.re did not confirm the account.');
        }
        $profile = $response->json('current_user');
        $id = $profile['id'] ?? null;
        $username = $profile['username'] ?? null;
        if (
            !(is_int($id) || is_string($id)) || !preg_match('/^[1-9][0-9]{0,15}$/D', (string) $id) ||
            !is_string($username) || trim($username) === '' || mb_strlen($username) > 60
        ) {
            throw new RuntimeException('Invalid Cfx.re account.');
        }

        return new User()->map(['id' => (string) $id, 'nickname' => $username, 'name' => $username]);
    }
}
