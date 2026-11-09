<?php

namespace App\Kraft\Auth;

use App\Kraft\Extensions\ExtensionManager;
use App\Kraft\Settings;
use InvalidArgumentException;

class PermissionCatalog
{
    private const array CORE_PERMISSIONS = [
        'admin.access',
        'maintenance.access',
        'settings.manage',
        'users.manage',
        'users.create',
        'users.view-personal',
        'users.edit-personal',
        'users.security',
        'users.ban',
        'users.delete',
        'roles.manage',
        'content.manage',
        'content.restore',
        'content.purge',
        'content.comments.manage',
        'wallet.manage',
        'servers.manage',
        'extensions.manage',
        'updates.manage',
        'imports.manage',
        'media.manage',
        'navigation.manage',
        'notifications.manage',
        'service-accounts.manage',
    ];

    private ?array $definitions = null;

    public static function corePermissions(): array
    {
        return self::CORE_PERMISSIONS;
    }

    public function all(): array
    {
        return array_keys($this->definitions());
    }

    public function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }
        $definitions = [];
        $labels = $this->coreLabels();
        foreach (self::CORE_PERMISSIONS as $permission) {
            $definitions[$permission] = [
                'label' => $labels[$permission],
                'default' => [
                    'admin' => true,
                    'mod' => in_array($permission, ['admin.access', 'content.comments.manage'], true),
                ],
            ];
        }
        foreach (app(ExtensionManager::class)->permissionDefinitions() as $permission => $definition) {
            $definitions[$permission] ??= $definition;
        }

        return $this->definitions = $definitions;
    }

    public function publicPermissions(): array
    {
        return array_keys(
            array_filter(
                $this->definitions(),
                fn ($definition) => $definition['default'] !== null &&
                    (array_key_exists('visitor', $definition['default']) ||
                        array_key_exists('member', $definition['default'])),
            ),
        );
    }

    public function privileged(): array
    {
        return array_values(array_diff($this->all(), $this->publicPermissions()));
    }

    public function defaults(string $audience): array
    {
        if ($audience === 'none') {
            return [];
        }
        if (!in_array($audience, ['visitor', 'member', 'mod', 'admin'], true)) {
            throw new InvalidArgumentException('Unknown permission audience.');
        }
        /** @var Settings $settings */
        $settings = app(Settings::class);
        $overrides = $settings->get('permissions.defaults', []);
        $public = array_fill_keys($this->publicPermissions(), true);
        $permissions = [];
        foreach ($this->definitions() as $permission => $definition) {
            $inherited =
                isset($public[$permission]) && in_array($audience, ['mod', 'admin'], true)
                    ? $overrides[$permission]['member'] ?? ($definition['default']['member'] ?? false)
                    : false;
            $enabled = $overrides[$permission][$audience] ?? ($definition['default'][$audience] ?? $inherited);
            if ($enabled === true) {
                $permissions[] = $permission;
            }
        }

        return $permissions;
    }

    public function groups(): array
    {
        $groups = [
            [
                'id' => 'core',
                'label' => __('admin.members.permissions_core'),
                'permissions' => self::CORE_PERMISSIONS,
            ],
        ];
        $assigned = array_fill_keys(self::CORE_PERMISSIONS, true);
        foreach (app(ExtensionManager::class)->permissionGroups() as $group) {
            $permissions = [];
            foreach ($group['permissions'] as $permission) {
                if (!isset($assigned[$permission])) {
                    $permissions[] = $permission;
                    $assigned[$permission] = true;
                }
            }
            if ($permissions) {
                $translated = __($group['label']);
                $groups[] = [
                    'id' => 'extension:'.$group['id'],
                    'label' => is_string($translated) && $translated !== $group['label'] ? $translated : $group['name'],
                    'permissions' => $permissions,
                ];
            }
        }

        return $groups;
    }

    public function label(string $permission): string
    {
        return __($this->definitions()[$permission]['label'] ?? $permission);
    }

    private function coreLabels(): array
    {
        return [
            'admin.access' => 'admin.dashboard.open',
            'maintenance.access' => 'admin.operations.maintenance',
            'settings.manage' => 'admin.settings.title',
            'users.manage' => 'common.labels.members',
            'users.create' => 'admin.members.permission_create',
            'users.view-personal' => 'admin.members.permission_view_personal',
            'users.edit-personal' => 'admin.members.permission_edit_personal',
            'users.security' => 'admin.members.permission_security',
            'users.ban' => 'admin.members.permission_ban',
            'users.delete' => 'admin.members.permission_delete',
            'roles.manage' => 'admin.members.roles_title',
            'content.manage' => 'admin.content.title',
            'content.restore' => 'site.content.permission_restore',
            'content.purge' => 'trash.permission_purge',
            'content.comments.manage' => 'site.content.permission_moderate',
            'wallet.manage' => 'site.account.wallet.admin_title',
            'servers.manage' => 'admin.servers.title',
            'extensions.manage' => 'admin.extensions.title',
            'updates.manage' => 'system.updates.title',
            'imports.manage' => 'system.imports.navigation',
            'media.manage' => 'site.community.media_title',
            'navigation.manage' => 'site.community.navigation_title',
            'notifications.manage' => 'site.community.notices_title',
            'service-accounts.manage' => 'service_accounts.permission',
        ];
    }
}
