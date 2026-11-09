<?php

namespace KraftInk\Plugins\Network\Services;

use Illuminate\Support\Facades\File;

class Configuration
{
    public function get(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            $path = storage_path('app/network.json');
        }
        if (!is_file($path)) {
            return ['mode' => 'cloudflare', 'proxies' => []];
        }
        $data = json_decode(File::get($path), true);
        if (!is_array($data)) {
            return ['mode' => 'direct', 'proxies' => []];
        }
        $mode = in_array($data['mode'] ?? null, ['direct', 'cloudflare', 'custom'], true) ? $data['mode'] : 'direct';
        $proxies = array_values(array_filter(
            is_array($data['proxies'] ?? null) ? $data['proxies'] : [],
            fn ($proxy) => is_string($proxy) && self::validProxy($proxy),
        ));

        return ['mode' => $mode, 'proxies' => $proxies];
    }

    public function save(string $mode, ?array $proxies = null): void
    {
        $data = $this->get();
        $data['mode'] = $mode;
        if ($proxies !== null) {
            $data['proxies'] = array_values(array_unique($proxies));
        }
        $path = $this->path();
        File::ensureDirectoryExists(dirname($path));
        File::replace($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    public static function validProxy(string $value): bool
    {
        $parts = explode('/', $value);
        if (!filter_var($parts[0], FILTER_VALIDATE_IP) || count($parts) > 2) {
            return false;
        }

        return count($parts) === 1 ||
            (ctype_digit($parts[1]) &&
                (int) $parts[1] >= 1 &&
                (int) $parts[1] <= (str_contains($parts[0], ':') ? 128 : 32));
    }

    private function path(): string
    {
        return storage_path('app/private/plugins/network.json');
    }
}
