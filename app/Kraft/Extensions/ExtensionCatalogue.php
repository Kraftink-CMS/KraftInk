<?php

namespace App\Kraft\Extensions;

use App\Kraft\Games\GameRegistry;
use App\Kraft\Updates\UpdateChannel;
use Composer\Semver\VersionParser;
use Throwable;

class ExtensionCatalogue
{
    public function for(string $type): array
    {
        $market = app(Market::class);
        $listing = $market->listingForNotifications();
        $manager = app(ExtensionManager::class);
        $installed = $manager->all();
        $game = app(GameRegistry::class)->activeId();
        $items = [];
        foreach ($listing['resources'] as $resource) {
            if ($resource['type'] !== $type || app(BuiltinExtensions::class)->contains($resource['slug'])) {
                continue;
            }
            $current = $installed[$resource['slug']] ?? null;
            $update = $current && $current['installed'];
            $purchased = $resource['commercial']['kind'] === 'paid' && ($resource['access']['allowed'] ?? null) === true;
            $allVersions = $resource['versions'];
            usort($allVersions, fn ($left, $right) => version_compare($right['version'], $left['version']));
            $versions = array_values(array_filter($allVersions, fn ($version) => GameSupport::matches($version, $game)));
            if (!$versions && !$purchased) {
                continue;
            }
            $compatible = null;
            foreach ($versions as $version) {
                if (!app(UpdateChannel::class)->allows(VersionParser::parseStability($version['version']))) {
                    continue;
                }
                try {
                    if ($current && (!$current['valid'] || $current['type'] !== $resource['type'])) {
                        continue;
                    }
                    $manager->assertMarketRequirements($resource, $version);
                    if ($update) {
                        $manager->assertReplacement(
                            [
                                'id' => $resource['slug'],
                                'type' => $resource['type'],
                                'target' => $current['target'] ?? null,
                                'version' => $version['version'],
                                'requires' => $version['requirements'],
                            ] + GameSupport::declarations($version),
                            $installed,
                        );
                    }
                    $compatible = $version;
                    break;
                } catch (Throwable) {
                }
            }
            $available =
                $resource['_source_state'] === 'fresh' &&
                ($resource['access']['allowed'] ?? true) &&
                !$resource['manual_only'] &&
                $compatible &&
                isset($compatible['download']) &&
                (!$update || version_compare($compatible['version'], $current['installed_version'], '>'));
            $items[] = [
                'id' => $resource['id'],
                'name' => $market->localized($resource['name']),
                'description' => $market->localized($resource['description']),
                'author' => $resource['author']['name'],
                'games' => $type === 'plugin' ? GameSupport::summary($compatible ?? $versions[0] ?? $allVersions[0]) : null,
                'price' => $purchased ? __('market.purchased') : $this->price($resource['commercial']),
                'purchased' => $purchased,
                'purchase_incompatibility' => $purchased && !$compatible
                    ? __($versions ? 'system.extensions.market_no_compatible_version' : 'system.extensions.game_incompatible')
                    : null,
                'source' => $resource['_source_id'],
                'version' => $compatible['version'] ?? null,
                'available' => (bool) $available,
                'state' => $resource['_source_state'],
                'update' => (bool) $update,
                'manual' => $resource['manual_only'],
                'denied' => ($resource['access']['allowed'] ?? true) === false,
                'reason' => ($resource['access']['allowed'] ?? true) === false
                        ? $resource['access']['message'] ?? __('market.errors.resource_denied')
                        : null,
                'url' => $resource['commercial']['url'] ?? ($resource['repository'] ?? null),
            ];
        }
        usort($items, fn ($left, $right) => strnatcasecmp($left['name'], $right['name']));

        return ['state' => $listing['state'], 'items' => $items, 'sources' => $listing['sources']];
    }

    private function price(array $commercial): string
    {
        $price = $commercial['price'] ?? null;
        if ($price === null) {
            return __(
                $commercial['kind'] === 'paid' ? 'system.extensions.market_paid' : 'system.extensions.market_free',
            );
        }
        $french = str_starts_with(strtolower(app()->getLocale()), 'fr');
        [$integer, $fraction] = array_pad(explode('.', $price['amount'], 2), 2, '');
        $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', $french ? "\u{202F}" : ',', $integer);
        if ($fraction !== '') {
            $integer .= ($french ? ',' : '.').$fraction;
        }

        return $integer.' '.$price['currency'];
    }
}
