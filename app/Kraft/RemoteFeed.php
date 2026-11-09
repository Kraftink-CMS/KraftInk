<?php

namespace App\Kraft;

use App\Models\RemoteFeedState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SodiumException;

abstract class RemoteFeed
{
    protected function fetchFeed(array $source, string $field): mixed
    {
        foreach ([$source['url'], $source[$field]] as $url) {
            $parts = is_string($url) ? parse_url($url) : null;
            if (
                !is_array($parts) ||
                ($parts['scheme'] ?? null) !== 'https' ||
                empty($parts['host']) ||
                !filter_var($url, FILTER_VALIDATE_URL) ||
                preg_match('/[\x00-\x20\x7f\\\\]/', $url) ||
                array_intersect(['user', 'pass', 'port', 'query', 'fragment'], array_keys($parts))
            ) {
                throw new RuntimeException(__('market.security.invalid'));
            }
        }
        if (
            strtolower(parse_url($source['url'], PHP_URL_HOST)) !== strtolower(parse_url($source[$field], PHP_URL_HOST))
        ) {
            throw new RuntimeException(__('market.security.invalid'));
        }
        $response = Http::acceptJson()
            ->timeout(5)
            ->connectTimeout(3)
            ->withOptions([
                'allow_redirects' => false,
                'on_headers' => function ($response): void {
                    if ((int) $response->getHeaderLine('Content-Length') > 2097152) {
                        throw new RuntimeException(__('market.security.invalid'));
                    }
                },
                'progress' => function ($total, $downloaded): void {
                    if ($downloaded > 2097152) {
                        throw new RuntimeException(__('market.security.invalid'));
                    }
                },
            ])
            ->get($source[$field]);
        if ($response->status() !== 200 || strlen($response->body()) > 2097152) {
            throw new RuntimeException(__('market.security.invalid'));
        }

        return $response->json();
    }

    protected function publicKey(?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        return base64_decode(app(SigningKeys::class)->publicKey($id), true);
    }

    protected function acceptFeed(array $source, string $channel, mixed $input, ?callable $preserve = null): void
    {
        $keyId = $source['signing_key'] ?? null;
        if ($keyId !== null && !is_string($keyId)) {
            throw new RuntimeException(__('market.security.invalid'));
        }
        $keyId = $keyId === '' ? null : $keyId;
        $key = $this->publicKey($keyId);
        if ($key !== null) {
            if (
                !is_array($input) ||
                !is_string($input['payload'] ?? null) ||
                !is_string($input['signature'] ?? null) ||
                strlen($input['signature']) > 128 ||
                strlen($input['payload']) > 1800000
            ) {
                throw new RuntimeException(__('market.security.invalid'));
            }
            try {
                $payload = sodium_base642bin($input['payload'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
                $signature = sodium_base642bin($input['signature'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
            } catch (SodiumException $exception) {
                throw new RuntimeException(__('market.security.invalid'), previous: $exception);
            }
            if (
                $payload === '' ||
                strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES ||
                !sodium_crypto_sign_verify_detached($signature, $payload, $key)
            ) {
                throw new RuntimeException(__('market.security.invalid'));
            }
            $input = json_decode($payload, true, 24, JSON_THROW_ON_ERROR);
        } elseif (is_array($input) && strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 2097152) {
            throw new RuntimeException(__('market.security.invalid'));
        }
        $document = $this->validateDocument($input);
        $context = [
            'mode' => $key === null ? 'plain' : 'signed',
            'key_id' => $keyId,
            'fingerprint' => $key === null ? null : hash('sha256', $key),
        ];
        DB::transaction(function () use ($source, $channel, $document, $context, $preserve): void {
            $state = RemoteFeedState::query()
                ->where('issuer_id', $source['id'])
                ->where('channel', $channel)
                ->lockForUpdate()
                ->first();
            if ($state) {
                $previous = $this->validateDocument($state->document);
                $trust = $state->auth_context;
                if (
                    !$this->validContext($trust) ||
                    (($trust['mode'] ?? null) === 'signed' &&
                        ($context['mode'] !== 'signed' ||
                            (($trust['key_id'] ?? null) === $context['key_id'] &&
                                ($trust['fingerprint'] ?? null) !== $context['fingerprint']))) ||
                    $document['sequence'] < $previous['sequence'] ||
                    ($document['sequence'] === $previous['sequence'] && $document !== $previous)
                ) {
                    throw new RuntimeException(__('market.security.invalid'));
                }
                if ($preserve !== null) {
                    $preserve($previous, $document);
                }
            }
            $values = ['document' => $document, 'auth_context' => $context];
            if ($state) {
                $state->fill($values)->save();
            } else {
                RemoteFeedState::query()->create(['issuer_id' => $source['id'], 'channel' => $channel, ...$values]);
            }
        }, 3);
    }

    protected function storedFeed(string $issuer, string $channel, ?array $source = null): ?array
    {
        $state = RemoteFeedState::query()->where('issuer_id', $issuer)->where('channel', $channel)->first();

        if ($state && !$this->validContext($state->auth_context)) {
            throw new RuntimeException(__('market.security.invalid'));
        }

        if (
            $state &&
            $state->auth_context['mode'] === 'plain' &&
            ($source === null || ($source['signing_key'] ?? null) !== null)
        ) {
            return null;
        }

        return $state ? $this->validateDocument($state->document) : null;
    }

    private function validContext(mixed $context): bool
    {
        if (!is_array($context)) {
            return false;
        }
        if (($context['mode'] ?? null) === 'plain') {
            return ($context['key_id'] ?? null) === null && ($context['fingerprint'] ?? null) === null;
        }

        return ($context['mode'] ?? null) === 'signed' &&
            is_string($context['key_id'] ?? null) &&
            trim($context['key_id']) !== '' &&
            strlen($context['key_id']) <= 128 &&
            is_string($context['fingerprint'] ?? null) &&
            preg_match('/^[a-f0-9]{64}$/D', $context['fingerprint']) === 1;
    }

    protected function validateDocument(mixed $document): array
    {
        if (
            !is_array($document) ||
            ($document['schema_version'] ?? null) !== 1 ||
            !is_int($document['sequence'] ?? null) ||
            $document['sequence'] < 0 ||
            !is_int($document['issued_at'] ?? null) ||
            $document['issued_at'] < 0 ||
            $document['issued_at'] > now()->timestamp + 300
        ) {
            throw new RuntimeException(__('market.security.invalid'));
        }

        return $document;
    }
}
