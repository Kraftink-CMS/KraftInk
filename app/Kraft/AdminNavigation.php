<?php

namespace App\Kraft;

use App\Kraft\Extensions\ExtensionManager;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\Route;
use App\Kraft\Link\DeliveryMonitor;

class AdminNavigation
{
    public function build(User $user): array
    {
        $permissions = collect($user->effectivePermissions());
        $deliveryPermissions = array_column(app(DeliveryMonitor::class)->sources($user), 'permission');
        $sections = [
            [
                'id' => 'site',
                'label' => 'admin.navigation.section_site',
                'items' => [
                    $this->group('content', 'admin.navigation.content', 'files', [
                        $this->page('admin/news', 'admin.news.title', 'newspaper', 'content.manage'),
                        $this->page('admin/pages', 'admin.pages.title', 'file-earmark-richtext', 'content.manage'),
                        $this->page('admin/media', 'site.community.media_title', 'images', 'media.manage'),
                        $this->page(
                            'admin/content/comments',
                            'site.content.comments',
                            'chat-left-text',
                            'content.comments.manage',
                        ),
                        $this->page('admin/trash', 'trash.title', 'trash', null) + ['position' => 900],
                    ]),
                    $this->group('presentation', 'admin.navigation.presentation', 'layout-text-window', [
                        $this->page('admin/home', 'admin.home.title', 'house-gear', 'settings.manage'),
                        $this->page('admin/slides', 'admin.slides.title', 'collection-play', 'content.manage'),
                        $this->page('admin/widgets', 'widgets.title', 'grid-1x2', 'content.manage'),
                        $this->page(
                            'admin/navigation',
                            'site.community.navigation_title',
                            'list-nested',
                            'navigation.manage',
                        ),
                        $this->page('admin/social', 'admin.social.title', 'share', 'navigation.manage'),
                        $this->page('admin/seo', 'seo.title', 'search', 'settings.manage'),
                        $this->page(
                            'admin/extensions/themes',
                            'admin.navigation.themes',
                            'palette',
                            'extensions.manage',
                        ),
                    ]),
                ],
            ],
            [
                'id' => 'community',
                'label' => 'admin.navigation.section_community',
                'items' => [
                    $this->group('members', 'admin.navigation.members', 'people', [
                        $this->page('admin/users', 'common.labels.members', 'person', 'users.manage'),
                        $this->page('admin/roles', 'admin.members.roles_title', 'shield-check', 'roles.manage'),
                        $this->page('admin/wallet', 'site.account.wallet.admin_title', 'wallet2', 'wallet.manage'),
                        $this->page(
                            'admin/notifications',
                            'site.community.notices_title',
                            'megaphone',
                            'notifications.manage',
                        ),
                    ]),
                    $this->group('servers', 'admin.navigation.servers', 'hdd-network', [
                        $this->page('admin/games', 'site.games.title', 'controller', null) + [
                            'permissions' => ['settings.manage', 'extensions.manage'],
                        ],
                        $this->page('admin/servers', 'admin.servers.title', 'hdd-network', 'servers.manage'),
                        $this->page('admin/servers/deliveries', 'admin.servers.deliveries.title', 'box-seam', null) + [
                            'permissions' => array_unique(['servers.manage', ...$deliveryPermissions]),
                        ],
                    ]),
                    $this->group('extensions', 'admin.navigation.extension_tools', 'puzzle', []),
                ],
            ],
            [
                'id' => 'administration',
                'label' => 'admin.navigation.section_administration',
                'items' => [
                    $this->group('settings', 'admin.navigation.settings', 'sliders', [
                        $this->page('admin/settings/site', 'admin.settings.title', 'sliders', 'settings.manage'),
                        $this->page(
                            'admin/settings/avatars',
                            'admin.settings.avatars.title',
                            'person-square',
                            'settings.manage',
                        ),
                        $this->page(
                            'admin/settings/system',
                            'admin.settings.system.title',
                            'envelope',
                            'settings.manage',
                        ),
                    ]),
                    $this->group('security', 'admin.navigation.security', 'shield-lock', [
                        $this->page(
                            'admin/settings/accounts',
                            'auth_flow.admin.heading',
                            'person-gear',
                            'settings.manage',
                        ) + ['aliases' => ['admin/settings/oauth']],
                        $this->page(
                            'admin/settings/security',
                            'admin.settings.security.title',
                            'shield-check',
                            'settings.manage',
                        ),
                        $this->page(
                            'admin/settings/api',
                            'admin.integrations.auth_api.title',
                            'key',
                            'settings.manage',
                        ),
                        $this->page(
                            'admin/service-accounts',
                            'service_accounts.title',
                            'robot',
                            'service-accounts.manage',
                        ),
                    ]),
                    $this->group('integrations', 'admin.navigation.integrations', 'plug', [
                        $this->page(
                            'admin/content/announcements',
                            'admin.integrations.announcements.title',
                            'discord',
                            ['content.manage', 'settings.manage'],
                        ),
                        $this->page('admin/settings/discord', 'admin.integrations.discord.title', 'discord', [
                            'settings.manage',
                            'roles.manage',
                        ]),
                        $this->page(
                            'admin/operations/webhooks',
                            'admin.operations.webhooks',
                            'send',
                            'settings.manage',
                        ),
                    ]),
                    $this->page('admin/extensions/plugins', 'admin.navigation.plugins', 'puzzle', 'extensions.manage'),
                    $this->page('admin/extensions/market', 'market.settings.title', 'shop', 'extensions.manage'),
                    $this->group('operations', 'admin.navigation.operations', 'tools', [
                        $this->page(
                            'admin/operations/maintenance',
                            'admin.operations.maintenance',
                            'cone-striped',
                            'settings.manage',
                        ),
                        $this->page('admin/operations/cache', 'admin.operations.cache', 'lightning', 'settings.manage'),
                        $this->page(
                            'admin/operations/retention',
                            'retention.title',
                            'database-gear',
                            'settings.manage',
                        ),
                        $this->page(
                            'admin/operations/audit',
                            'admin.operations.audit',
                            'journal-text',
                            'settings.manage',
                        ),
                    ]),
                    $this->group('deployment', 'admin.navigation.deployment', 'arrow-repeat', [
                        $this->page('admin/updates', 'system.updates.title', 'arrow-repeat', 'updates.manage'),
                        $this->page(
                            'admin/imports',
                            'site.home.import_a_website',
                            'box-arrow-in-down',
                            'imports.manage',
                        ),
                    ]),
                ],
            ],
        ];
        $pending = $this->extensionNodes();
        do {
            $attached = false;
            foreach ($pending as $key => $entry) {
                if ($this->attach($sections, $entry['node'], $entry['parent'], $entry['section'])) {
                    unset($pending[$key]);
                    $attached = true;
                }
            }
        } while ($attached && $pending);
        foreach ($pending as $entry) {
            $this->attach($sections, $entry['node'], 'extensions', 'community');
        }
        $bestPath = null;
        $bestKey = null;
        $filter = function (array $items) use (&$filter, $permissions, &$bestPath, &$bestKey): array {
            $result = [];
            foreach ($items as $index => &$item) {
                $item['position'] ??= ($index + 1) * 100;
            }
            unset($item);
            usort($items, fn ($left, $right) => $left['position'] <=> $right['position']);
            foreach ($items as $item) {
                $required = $item['permissions'] ?? (empty($item['permission']) ? [] : [$item['permission']]);
                if ($required && !$permissions->contains('*') && $permissions->intersect($required)->isEmpty()) {
                    continue;
                }
                if (
                    !$permissions->contains('*') &&
                    array_diff($item['required_permissions'] ?? [], $permissions->all())
                ) {
                    continue;
                }
                if (isset($item['items'])) {
                    $item['items'] = $filter($item['items']);
                    if (!$item['items']) {
                        continue;
                    }
                } else {
                    foreach ([$item['path'], ...($item['aliases'] ?? [])] as $path) {
                        if (
                            (request()->is($path) || request()->is($path.'/*')) &&
                            ($bestPath === null || strlen($path) > strlen($bestPath))
                        ) {
                            $bestPath = $path;
                            $bestKey = $item['key'];
                        }
                    }
                }
                $result[] = $item;
            }

            return $result;
        };
        foreach ($sections as &$section) {
            $section['items'] = $filter($section['items']);
        }
        unset($section);
        $mark = function (array $items) use (&$mark, $bestKey): array {
            foreach ($items as &$item) {
                if (isset($item['items'])) {
                    $item['items'] = $mark($item['items']);
                    $item['active'] = collect($item['items'])->contains('active', true);
                } else {
                    $item['active'] = $item['key'] === $bestKey;
                }
            }

            return $items;
        };
        foreach ($sections as &$section) {
            $section['items'] = $mark($section['items']);
        }

        return array_values(array_filter($sections, fn ($section) => $section['items'] !== []));
    }

    private function extensionNodes(): array
    {
        $items = [];
        foreach (app(ExtensionManager::class)->contributors() as $extension) {
            $defaults = [];
            foreach ($extension['navigation']['admin'] ?? [] as $index => $entry) {
                $node = $this->extensionNode($entry, $extension['id'], (string) $index);
                if (!$node) {
                    continue;
                }
                $parent = array_key_exists('parent', $entry) ? $entry['parent'] : 'extensions';
                if ($parent === 'extensions') {
                    $defaults[] = $node;
                } else {
                    $items[] = ['node' => $node, 'parent' => $parent, 'section' => $entry['section'] ?? 'community'];
                }
            }
            if ($defaults) {
                $node =
                    count($defaults) === 1
                        ? $defaults[0]
                        : $this->group(
                            'extension-'.$extension['id'],
                            $defaults[0]['label'],
                            $defaults[0]['icon'],
                            $defaults,
                        );
                $items[] = ['node' => $node, 'parent' => 'extensions', 'section' => 'community'];
            }
        }

        return $items;
    }

    private function extensionNode(array $entry, string $extension, string $key): ?array
    {
        $icon = $entry['icon'] ?? 'circle';
        if (is_array($icon)) {
            $icon['extension'] = $extension;
        }
        if (isset($entry['children'])) {
            $children = [];
            foreach ($entry['children'] as $index => $child) {
                if ($node = $this->extensionNode($child, $extension, $key.'-'.$index)) {
                    $children[] = $node;
                }
            }
            if (!$children) {
                return null;
            }
            $node = $this->group(
                'extension-'.$extension.'-'.($entry['id'] ?? $key),
                $entry['label'],
                $icon,
                $children,
            );
            $node['permission'] = $entry['permission'] ?? null;
        } else {
            $route = Route::getRoutes()->getByName($entry['route']);
            if (!$route || !in_array('GET', $route->methods(), true) || $route->parameterNames()) {
                return null;
            }
            $address = route($entry['route']);
            $node = $this->page(
                ltrim(substr($address, strlen(url('/'))), '/'),
                $entry['label'],
                $icon,
                $entry['permission'] ?? null,
            );
            $node['url'] = $address;
            $node['key'] = 'extension-'.$extension.'-'.$key;
        }
        $node['position'] = $entry['position'] ?? 500;
        $node['permissions'] = $entry['permissions'] ?? (isset($entry['permission']) ? [$entry['permission']] : []);

        return $node;
    }

    private function attach(array &$sections, array $node, ?string $parent, string $section): bool
    {
        foreach ($sections as &$block) {
            if ($parent === null && $block['id'] === $section) {
                $block['items'][] = $node;

                return true;
            }
            if ($parent !== null && $this->append($block['items'], $node, $parent)) {
                return true;
            }
        }

        return false;
    }

    private function append(array &$items, array $node, string $parent): bool
    {
        foreach ($items as &$item) {
            if (isset($item['items'])) {
                if ($item['id'] === $parent) {
                    $item['items'][] = $node;

                    return true;
                }
                if ($this->append($item['items'], $node, $parent)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function page(string $path, string $label, string|array $icon, string|array|null $permission): array
    {
        return compact('path', 'label', 'icon') + [
            'permission' => is_string($permission) ? $permission : null,
            'required_permissions' => is_array($permission) ? $permission : [],
            'url' => url($path),
            'key' => 'native-'.$path,
        ];
    }

    private function group(string $id, string $label, string|array $icon, array $items): array
    {
        return compact('id', 'label', 'icon', 'items');
    }
}
