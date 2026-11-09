<?php

namespace App\Kraft\System;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class WebhookClient
{
    public function __construct(private readonly WebhookTarget $target)
    {
    }

    public function postJson(string $url, string $body, array $headers = []): Response
    {
        return $this->request($url)->withHeaders($headers)->withBody($body)->post($url);
    }

    public function discord(string $url, array $embeds): array
    {
        if (!self::validDiscordUrl($url)) {
            throw new InvalidArgumentException('Invalid Discord webhook URL.');
        }
        $response = $this->request($url)->acceptJson()->post($url.'?wait=true', [
            'allowed_mentions' => ['parse' => []],
            'embeds' => $embeds,
        ]);
        $httpStatus = $response->status();
        $status = match (true) {
            $response->successful() => 'delivered',
            $response->serverError() => 'uncertain',
            default => 'failed',
        };
        $message = $status === 'delivered' ? $response->json('id') : null;
        $delay = $httpStatus === 429 ? $response->json('retry_after') : null;

        return [
            'status' => $status,
            'http_status' => $httpStatus,
            'message_id' => is_string($message) && preg_match('/^[0-9]{17,22}$/D', $message) ? $message : null,
            'retry_after' => $httpStatus === 429 ? $this->retryDelay($delay) : null,
        ];
    }

    public static function validDiscordUrl(string $url): bool
    {
        return (bool) preg_match(
            '~^https://discord\.com/api/(?:v10/)?webhooks/[0-9]{17,22}/[A-Za-z0-9._-]{20,200}$~D',
            $url,
        );
    }

    private function request(string $url): PendingRequest
    {
        return Http::withOptions($this->target->options($url))->connectTimeout(3)->timeout(10);
    }

    private function retryDelay(mixed $delay): int
    {
        return is_numeric($delay) && is_finite((float) $delay)
            ? (int) max(1, min(86400, ceil((float) $delay)))
            : 60;
    }
}
