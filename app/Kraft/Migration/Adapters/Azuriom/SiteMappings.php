<?php

namespace App\Kraft\Migration\Adapters\Azuriom;

use App\Kraft\Migration\ImportContext;
use App\Kraft\Migration\MappingRegistry;
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
        $registry->register('azuriom', 'settings', $this->configuration(...), 5);
        $registry->register('azuriom', 'navbar_elements', $this->navigation(...), 95);
        $registry->register(
            'azuriom',
            'navbar_element_role',
            $this->navigationRole(...),
            96,
            dependencies: ['navbar_elements', 'roles'],
        );
        $registry->register(
            'azuriom',
            'social_links',
            fn (array $row, ImportContext $context) => $this->links->socialLink($row, $context, 'social_links'),
            97,
        );
        $registry->register('azuriom', 'images', $this->image(...), 98);
        $registry->register('azuriom', 'attachments', $this->attachment(...), 98);
        $registry->register('azuriom', 'pending_attachments', $this->attachment(...), 98);
    }

    /**
     * @throws JsonException
     */
    public function configuration(array $row, ImportContext $context): array
    {
        return $this->settings->convert(
            [(string) $row['name'] => $row['value']],
            $context,
            'settings',
            $row['id'],
            [
                'name' => 'site.name',
                'description' => 'site.description',
                'copyright' => 'site.copyright',
                'locale' => 'site.locale',
                'lang' => 'site.locale',
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
        $table = 'navbar_elements';
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
        $descriptor = ['type' => $row['type'], 'value' => $row['value']];
        $destination = $this->destination($descriptor, $context);
        $restricted = false;
        $grants = $context->sourceRows('navbar_element_role');
        if ($grants === null) {
            $destination['review'] = true;
        } else {
            foreach ($grants as $grant) {
                if ((string) $grant['navbar_element_id'] === (string) $row['id']) {
                    $restricted = true;
                    break;
                }
            }
        }
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
            'roles_restricted' => $restricted,
            'icon' => preg_match('/^[a-z][a-z0-9-]{0,59}$/D', (string) ($row['icon'] ?? '')) ? $row['icon'] : null,
            'position' => max(0, (int) ($row['position'] ?? ($row['order'] ?? 0))),
            'enabled' => false,
            'new_tab' => !empty($row['new_tab']) || !empty($row['open_new_tab']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['target_table' => 'navigation_links', 'target_id' => $id];
    }

    /**
     * @throws JsonException
     */
    public function navigationRole(array $row, ImportContext $context): array
    {
        $id = $context->required('navbar_elements', $row['navbar_element_id']);
        $context->target->table('navigation_link_role')->insertOrIgnore([
            'navigation_link_id' => $id,
            'role_id' => $context->required('roles', $row['role_id']),
        ]);
        $context->target
            ->table('navigation_links')
            ->where('id', $id)
            ->update(['roles_restricted' => true]);

        return ['target_table' => 'navigation_links', 'target_id' => $id];
    }

    /**
     * @throws JsonException
     */
    public function image(array $row, ImportContext $context): array
    {
        $context->report('media', [
            'table' => 'images',
            'id' => $row['id'],
            'field' => 'file',
            'path' => app(MediaReferences::class)->path((string) ($row['file'] ?? '')),
            'reason' => 'file_transfer_required',
        ]);

        return ['metadata' => ['file_transfer_required' => true]];
    }

    /**
     * @throws JsonException
     */
    public function attachment(array $row, ImportContext $context): array
    {
        $type = (string) ($row['attachable_type'] ?? ($row['pending_type'] ?? ''));
        $filename = (string) ($row['file'] ?? '');
        $path = $filename;
        if (preg_match('/^[a-z][a-z0-9]*(?:[._][a-z0-9]+)+$/D', $type) && basename($filename) === $filename) {
            $path = 'storage/app/public/'.str_replace(['.', '_'], '/', $type).'/attachments/'.$filename;
        }
        $context->report('media', [
            'table' => isset($row['attachable_type']) ? 'attachments' : 'pending_attachments',
            'id' => $row['id'],
            'field' => 'file',
            'path' => $path,
            'owner_type' => $type,
            'owner_id' => $row['attachable_id'] ?? ($row['pending_id'] ?? null),
            'reason' => 'file_transfer_required',
        ]);

        return ['metadata' => ['archive_only' => true, 'file_transfer_required' => true]];
    }

    private function destination(array $descriptor, ImportContext $context): array
    {
        if (in_array($descriptor['type'] ?? null, ['page', 'post'], true)) {
            $descriptor['source_table'] = $descriptor['type'] === 'page' ? 'pages' : 'posts';
        }

        return $this->links->destination($descriptor, $context);
    }
}
