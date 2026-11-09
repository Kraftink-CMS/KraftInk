<?php

namespace App\Kraft\Migration;

use App\Kraft\Extensions\ExtensionManager;
use App\Kraft\NavigationTargets;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

final readonly class NavigationMappings
{
    public function socialLink(array $row, ImportContext $context, string $sourceTable): array
    {
        $url = (string) ($row['value'] ?? $row['url'] ?? '');
        if (!NavigationTargets::validUrl($url)) {
            $context->report('unsupported', [
                'table' => $sourceTable,
                'id' => $row['id'],
                'reason' => 'invalid_social_link',
            ]);

            return ['metadata' => ['review' => true]];
        }
        $type = strtolower((string) ($row['type'] ?? $row['title'] ?? 'website'));
        $type = in_array($type, ['twitter', 'x-twitter'], true) ? 'x' : $type;
        $services = config('social.services');
        $service = $services[$type] ?? $services['website'];
        $properties = $row['properties'] ?? [];
        if (is_string($properties)) {
            $properties = json_decode($properties, true);
        }
        $properties = is_array($properties) ? $properties : [];
        $json = $context->target->table('settings')->where('key', 'social.links')->value('value');
        $links = json_decode($json ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        $id = (string) Str::uuid();
        $links[] = [
            'id' => $id,
            'type' => array_key_exists($type, $services) ? $type : 'website',
            'title' => strip_tags((string) ($properties['title'] ?? $row['title'] ?? $service['name'] ?? $type)),
            'url' => $url,
            'icon' => $service['icon'],
            'color' => $service['color'],
            'enabled' => false,
            'position' => max(0, (int) ($row['position'] ?? $row['order'] ?? 0)),
        ];
        usort($links, fn (array $left, array $right) => ($left['position'] ?? 0) <=> ($right['position'] ?? 0));
        $context->setting('social.links', $links);

        return ['metadata' => ['social_link_id' => $id]];
    }

    public function destination(array $descriptor, ImportContext $context): array
    {
        $type = $descriptor['type'] ?? 'link';
        $value = (string) ($descriptor['value'] ?? ($descriptor['url'] ?? ''));
        $content = null;
        $review = false;
        if ($type === 'page' || $type === 'post') {
            $source = $descriptor['source_table'];
            foreach ($context->indexedSourceRows($source) as $row) {
                if (
                    (isset($descriptor['id']) && (string) $row['id'] === (string) $descriptor['id']) ||
                    (string) ($row['slug'] ?? '') === $value
                ) {
                    $content = $context->mapped($source, $row['id']);
                    break;
                }
            }
            $entry =
                $content === null
                    ? null
                    : $context->target
                        ->table('content_entries')
                        ->where('id', $content)
                        ->first(['kind', 'slug']);
            $value = $entry ? ($entry->kind === 'page' ? '/p/' : '/news/').$entry->slug : '/';
            $review = $entry === null;
        } elseif ($type === 'home') {
            $value = '/';
        } elseif ($type === 'posts') {
            $value = '/news';
        } elseif ($type === 'plugin') {
            $name = explode('.', $value)[0];
            $target = app(ImportExtensions::class)->equivalent($name, 'plugin', $context->adapter->id());
            $extension = $target === null ? null : app(ExtensionManager::class)->all()[$target] ?? null;
            $route = $extension['navigation']['public'][0]['route'] ?? null;
            $value = is_string($route) && Route::has($route) ? route($route, absolute: false) : '/';
            $review = $value === '/';
        } elseif (in_array($type, ['dropdown', 'submenu'], true)) {
            $value = '/';
        }
        if (!NavigationTargets::validUrl($value)) {
            $value = '/';
            $review = true;
        }

        return ['url' => $value, 'content_id' => $content, 'review' => $review];
    }
}
