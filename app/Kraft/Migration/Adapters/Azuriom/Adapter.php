<?php

namespace App\Kraft\Migration\Adapters\Azuriom;

use App\Kraft\Migration\AbstractSourceAdapter;
use App\Kraft\Migration\MediaReferences;
use App\Kraft\Migration\ImportContext;
use Illuminate\Database\Connection;
use App\Kraft\Migration\CoreMappings as CoreRecords;
use App\Kraft\Migration\MappingRegistry;
use JsonException;

final class Adapter extends AbstractSourceAdapter
{
    public function id(): string
    {
        return 'azuriom';
    }

    public function requirements(): array
    {
        return [
            'users' => ['id', 'name', 'email', 'password'],
            'roles' => ['id', 'name'],
            'pages' => ['id', 'title', 'content'],
        ];
    }

    public function roleTable(): string
    {
        return 'roles';
    }

    public function newsTable(): string
    {
        return 'posts';
    }

    public function user(array $row, ImportContext $context): array
    {
        return [
            'username' => $row['name'],
            'email' => $row['email'],
            'password' => $row['password'],
            'identities' => $this->normalizeIdentities(
                !empty($row['game_id']) && ($context->options['game'] ?? null) !== null
                    ? [
                        [
                            'game' => $context->options['game'],
                            'uuid' => $row['game_id'],
                            'username' => $row['name'],
                        ],
                    ]
                    : [],
            ),
            'balance' => $row['money'] ?? 0,
            'role' => $row['role_id'] ?? null,
            'email_verified_at' => $row['email_verified_at'] ?? null,
            'locked' => !empty($row['two_factor_secret']) || !empty($row['is_banned']) || !empty($row['deleted_at']),
        ];
    }

    public function registerMappings(MappingRegistry $registry, CoreRecords $core): void
    {
        $mappings = app(CoreMappings::class);
        $registry->register($this->id(), 'roles', $mappings->role(...), 10);
        $registry->register($this->id(), 'users', $mappings->user(...), 20);
        $registry->register($this->id(), 'bans', $mappings->lockUser(...), 25);
        $registry->register(
            $this->id(),
            'pages',
            fn (array $row, $context) => $mappings->content($row, $context, 'page'),
            30,
            replacements: fn () => $core->contentReplacements('page'),
        );
        $registry->register(
            $this->id(),
            'posts',
            fn (array $row, $context) => $mappings->content($row, $context, 'news'),
            30,
            replacements: fn () => $core->contentReplacements('news'),
        );
        $registry->register($this->id(), 'page_role', $mappings->pageAccess(...), 35, dependencies: ['pages', 'roles']);
        $registry->register($this->id(), 'comments', $core->comment(...), 35, dependencies: ['posts', 'users']);
        $registry->register($this->id(), 'likes', $core->reaction(...), 35, dependencies: ['posts', 'users']);
        $registry->register($this->id(), 'servers', $mappings->server(...), 40);
        app(SiteMappings::class)->register($registry);
    }

    public function sourceLocale(Connection $connection, array $tables): ?string
    {
        if (!isset($tables['settings']) || !in_array('name', $tables['settings']['columns'], true)) {
            return null;
        }

        return ImportContext::normalizeLocale($connection->table('settings')->where('name', 'locale')->value('value'));
    }

    public function extensionNames(Connection $connection, array $tables): array
    {
        $theme =
            isset($tables['settings']) && in_array('name', $tables['settings']['columns'], true)
                ? $connection->table('settings')->where('name', 'theme')->value('value')
                : null;

        return ['plugin' => [], 'theme' => is_string($theme) && $theme !== '' ? [$theme] : []];
    }

    /**
     * @throws JsonException
     */
    public function afterMapping(array $row, ImportContext $context, string $table): void
    {
        app(MediaReferences::class)->inspect($row, $context, $table);
    }
}
