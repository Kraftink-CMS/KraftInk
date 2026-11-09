<?php

namespace App\Kraft\Migration\Adapters\MineWeb;

use App\Kraft\Migration\ImportContext;
use App\Kraft\Migration\MappingRegistry;
use App\Kraft\Migration\ImportExtensions;
use App\Kraft\Migration\SettingsMappings;
use App\Kraft\Migration\NavigationMappings;
use App\Kraft\Migration\MediaReferences;
use RuntimeException;
use JsonException;

final class SiteMappings
{
    public function __construct(private SettingsMappings $settings, private NavigationMappings $links)
    {
    }

    public function register(MappingRegistry $registry): void
    {
        $registry->register('mineweb', 'configurations', $this->configuration(...), 5);
        $registry->register('mineweb', 'seo', $this->seo(...), 94);
        $registry->register('mineweb', 'navbars', $this->navigation(...), 95);
        $registry->register(
            'mineweb',
            'social_buttons',
            fn (array $row, ImportContext $context) => $this->links->socialLink($row, $context, 'social_buttons'),
            97,
        );
        $registry->register('mineweb', 'sliders', $this->slide(...), 98);
        $registry->register('mineweb', 'plugins', $this->extension(...), 99);
    }

    /**
     * @throws JsonException
     */
    public function configuration(array $row, ImportContext $context): array
    {
        return $this->settings->convert(
            array_diff_key($row, ['id' => true]),
            $context,
            'configurations',
            $row['id'],
            [
                'name' => 'site.name',
                'description' => 'site.description',
                'copyright' => 'site.copyright',
                'lang' => 'site.locale',
                'locale' => 'site.locale',
                'timezone' => 'site.timezone',
                'email' => 'contact.email',
                'register' => 'registration.enabled',
            ],
            [
                'smtpHost' => 'mail.host',
                'smtpPort' => 'mail.port',
                'smtpUsername' => 'mail.username',
                'mail.smtp.host' => 'mail.host',
                'mail.smtp.port' => 'mail.port',
                'mail.smtp.username' => 'mail.username',
                'mail.from.address' => 'mail.from_address',
                'mail.from.name' => 'mail.from_name',
                'mail.smtp.scheme' => 'mail.security',
            ],
        );
    }

    /**
     * @throws JsonException
     */
    public function navigation(array $row, ImportContext $context, array $ancestors = []): array
    {
        $table = 'navbars';
        if (in_array((string) $row['id'], $ancestors, true)) {
            throw new RuntimeException('The source navigation contains a parent cycle.');
        }
        $parent = $row['parent_id'] ?? null;
        if ($parent !== null && $context->mapped($table, $parent) === null) {
            $source =
                $context->indexedSourceRows($table)[(string) $parent] ??
                throw new RuntimeException('Missing source navigation parent.');
            $context->remember(
                $table,
                $source,
                $this->navigation($source, $context, [...$ancestors, (string) $row['id']]),
            );
        }
        $descriptor = $this->decode($row['url'] ?? '') ?? ['type' => 'custom', 'url' => $row['url'] ?? '/'];
        $destination = $this->destination($descriptor, $context);
        $locale = config('imports.locale', 'fr');
        $id = $context->insert('navigation_links', [
            'parent_id' => $parent === null ? null : $context->required($table, $parent),
            'location' => 'header',
            'labels' => json_encode([$locale => strip_tags((string) $row['name'])], JSON_THROW_ON_ERROR),
            'url' => $destination['url'],
            'destination_type' => $destination['content_id'] === null ? 'url' : 'content',
            'content_id' => $destination['content_id'],
            'route_name' => null,
            'visibility' => 'all',
            'permission' => $destination['review'] ? 'imports.review' : null,
            'roles_restricted' => false,
            'icon' => preg_match('/^[a-z][a-z0-9-]{0,59}$/D', (string) ($row['icon'] ?? '')) ? $row['icon'] : null,
            'position' => max(0, (int) ($row['position'] ?? ($row['order'] ?? 0))),
            'enabled' => false,
            'new_tab' => !empty($row['new_tab']) || !empty($row['open_new_tab']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if (is_array($children = $this->decode($row['submenu'] ?? null))) {
            foreach ($children as $index => $child) {
                if (!is_array($child)) {
                    continue;
                }
                $link = $this->destination($this->decode($child['url'] ?? null) ?? $child, $context);
                $context->insert('navigation_links', [
                    'parent_id' => $id,
                    'location' => 'header',
                    'labels' => json_encode(
                        [$locale => strip_tags((string) ($child['name'] ?? ($child['title'] ?? '')))],
                        JSON_THROW_ON_ERROR,
                    ),
                    'url' => $link['url'],
                    'destination_type' => $link['content_id'] === null ? 'url' : 'content',
                    'content_id' => $link['content_id'],
                    'visibility' => 'all',
                    'permission' => $link['review'] ? 'imports.review' : null,
                    'position' => $index,
                    'enabled' => false,
                    'new_tab' => !empty($child['open_new_tab']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return ['target_table' => 'navigation_links', 'target_id' => $id];
    }

    /**
     * @throws JsonException
     */
    /**
     * @throws JsonException
     */
    public function seo(array $row, ImportContext $context): array
    {
        $entry = null;
        if (!empty($row['page'])) {
            $path = trim((string) $row['page'], '/');
            foreach (['pages', $context->adapter->newsTable()] as $source) {
                foreach ($context->indexedSourceRows($source) as $content) {
                    if (
                        in_array(
                            $path,
                            [
                                (string) ($content['slug'] ?? ''),
                                'p/'.($content['slug'] ?? ''),
                                'news/'.($content['slug'] ?? ''),
                            ],
                            true,
                        )
                    ) {
                        $entry = $context->mapped($source, $content['id']);
                        break 2;
                    }
                }
            }
            if ($entry === null) {
                $context->report('configuration', [
                    'table' => 'seo',
                    'id' => $row['id'],
                    'reason' => 'page_seo_review',
                ]);
            }
        }
        foreach (['title' => 'seo.title', 'description' => 'seo.description'] as $field => $key) {
            if (isset($row[$field])) {
                if ($entry !== null) {
                    $context->target
                        ->table('content_entries')
                        ->where('id', $entry)
                        ->update(['seo_'.$field => strip_tags((string) $row[$field])]);
                } elseif (empty($row['page'])) {
                    $context->setting($key, strip_tags((string) $row[$field]));
                }
            }
        }
        app(MediaReferences::class)->inspect($row, $context, 'seo');

        return ['metadata' => ['review' => true]];
    }

    /**
     * @throws JsonException
     */
    public function slide(array $row, ImportContext $context): array
    {
        $id = $context->insert('slides', [
            'title' => $row['title'] ?? null,
            'description' => $row['subtitle'] ?? null,
            'alt' => (string) ($row['title'] ?? ''),
            'media_asset_id' => null,
            'position' => max(0, (int) ($row['position'] ?? ($row['order'] ?? 0))),
            'enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(MediaReferences::class)->inspect($row, $context, 'sliders');

        return ['target_table' => 'slides', 'target_id' => $id];
    }

    /**
     * @throws JsonException
     */
    public function extension(array $row, ImportContext $context): array
    {
        $name = strtolower((string) ($row['name'] ?? ''));
        $equivalent = app(ImportExtensions::class)->equivalent($name, 'plugin', $context->adapter->id());
        if ($equivalent === null) {
            $context->report('extension', [
                'table' => 'plugins',
                'id' => $row['id'],
                'name' => $name,
                'reason' => 'extension_unavailable',
            ]);
        }

        return [
            'metadata' => [
                'equivalent' => $equivalent,
                '_import_outcome' => $equivalent === null ? 'archive_only' : 'converted',
            ],
        ];
    }

    private function destination(array $descriptor, ImportContext $context): array
    {
        if (in_array($descriptor['type'] ?? null, ['page', 'post'], true)) {
            $descriptor['source_table'] = $descriptor['type'] === 'page' ? 'pages' : 'news';
        }
        if (($descriptor['type'] ?? null) === 'plugin' && isset($descriptor['id'])) {
            $plugin = $context->indexedSourceRows('plugins')[(string) $descriptor['id']] ?? null;
            $descriptor['value'] = strtolower((string) ($plugin['name'] ?? $descriptor['id']));
            unset($descriptor['id']);
        }

        return $this->links->destination($descriptor, $context);
    }

    private function decode(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
