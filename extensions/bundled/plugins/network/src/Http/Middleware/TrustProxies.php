<?php

namespace KraftInk\Plugins\Network\Http\Middleware;

use App\Kraft\Extensions\ExtensionManager;
use KraftInk\Plugins\Network\Services\Configuration;

class TrustProxies extends \App\Http\Middleware\TrustProxies
{
    protected function proxies(): array
    {
        if (
            config('kraft.safe_mode') ||
            filter_var(getenv('KRAFT_SAFE_MODE'), FILTER_VALIDATE_BOOLEAN) ||
            empty(app(ExtensionManager::class)->all()['network']['enabled'])
        ) {
            return [];
        }
        $settings = app(Configuration::class)->get();

        return match ($settings['mode']) {
            'cloudflare' => config('network.cloudflare', []),
            'custom' => $settings['proxies'],
            default => [],
        };
    }
}
