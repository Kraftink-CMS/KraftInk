<?php

namespace App\Kraft\System;

use RuntimeException;

class WebhookTarget
{
    public function options(string $url): array
    {
        $parts = parse_url($url);
        if (
            !extension_loaded('curl') ||
            $parts === false ||
            !filter_var($url, FILTER_VALIDATE_URL) ||
            ($parts['scheme'] ?? '') !== 'https' ||
            ($parts['port'] ?? 443) !== 443 ||
            isset($parts['user']) ||
            isset($parts['pass']) ||
            isset($parts['fragment']) ||
            preg_match('/[\x00-\x20\x7f\\\\]/', $url)
        ) {
            throw new RuntimeException('Invalid webhook target.');
        }
        $host = $parts['host'] ?? '';
        if (!preg_match('/^[a-zA-Z0-9.-]+$/', $host)) {
            throw new RuntimeException('Invalid webhook host.');
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->addresses($host);
        if (!$addresses) {
            throw new RuntimeException('Webhook host unavailable.');
        }
        foreach ($addresses as $address) {
            if (
                !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) ||
                str_starts_with(strtolower($address), '::ffff:')
            ) {
                throw new RuntimeException('Webhook target is not public.');
            }
        }
        $addresses = array_map(
            fn ($address) => str_contains($address, ':') ? '['.$address.']' : $address,
            array_slice(array_values(array_unique($addresses)), 0, 8),
        );

        return [
            'allow_redirects' => false,
            'proxy' => '',
            'verify' => true,
            'curl' => [CURLOPT_RESOLVE => [$host.':443:'.implode(',', $addresses)]],
        ];
    }

    public function addresses(string $host): array
    {
        $ipv4 = @gethostbynamel($host) ?: [];
        $ipv6 = @dns_get_record($host, DNS_AAAA) ?: [];

        return array_values(array_unique([...$ipv4, ...array_column($ipv6, 'ipv6')]));
    }
}
