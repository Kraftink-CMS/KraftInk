<?php

namespace App\Kraft\Migration\Adapters\MineWeb;

use App\Kraft\Migration\AbstractSourceAdapter;
use App\Kraft\Migration\MediaReferences;
use App\Kraft\Migration\ImportContext;
use Illuminate\Database\Connection;
use App\Kraft\Migration\CoreMappings as CoreRecords;
use App\Kraft\Migration\MappingRegistry;
use DateTimeImmutable;
use JsonException;

final class Adapter extends AbstractSourceAdapter
{
    public function id(): string
    {
        return 'mineweb';
    }

    public function requirements(): array
    {
        return [
            'users' => ['id', 'pseudo', 'email', 'password', 'rank'],
            'ranks' => ['id', 'rank_id', 'name'],
            'news' => ['id', 'title', 'content'],
        ];
    }

    public function roleTable(): string
    {
        return 'ranks';
    }

    public function newsTable(): string
    {
        return 'news';
    }

    public function user(array $row, ImportContext $context): array
    {
        return [
            'username' => $row['pseudo'],
            'email' => $row['email'],
            'password' => $row['password'],
            'identities' => $this->normalizeIdentities(
                !empty($row['uuid']) && ($context->options['game'] ?? null) !== null
                    ? [
                        [
                            'game' => $context->options['game'],
                            'uuid' => $row['uuid'],
                            'username' => $row['pseudo'],
                        ],
                    ]
                    : [],
            ),
            'balance' => $row['money'] ?? 0,
            'role' => $row['rank'],
            'email_verified_at' => $this->verifiedAt($row['confirmed'] ?? null),
            'locked' => false,
        ];
    }

    public function registerMappings(MappingRegistry $registry, CoreRecords $core): void
    {
        $mappings = app(CoreMappings::class);
        $registry->register($this->id(), 'ranks', $mappings->role(...), 10, identity: 'rank_id');
        $registry->register($this->id(), 'users', $mappings->user(...), 20);
        $registry->register($this->id(), 'bans', $core->lockUser(...), 25);
        $registry->register($this->id(), 'users__twofactorauth', $mappings->twoFactor(...), 25);
        $registry->register(
            $this->id(),
            'pages',
            fn (array $row, $context) => $mappings->content($row, $context, 'page'),
            30,
            replacements: fn () => $core->contentReplacements('page'),
        );
        $registry->register(
            $this->id(),
            'news',
            fn (array $row, $context) => $mappings->content($row, $context, 'news'),
            30,
            replacements: fn () => $core->contentReplacements('news'),
        );
        $registry->register($this->id(), 'comments', $core->comment(...), 35, dependencies: ['news', 'users']);
        $registry->register($this->id(), 'likes', $core->reaction(...), 35, dependencies: ['news', 'users']);
        $registry->register($this->id(), 'servers', $core->server(...), 40);
        app(SiteMappings::class)->register($registry);
    }

    private function verifiedAt(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);

        return $date && $date->format('Y-m-d H:i:s') === $value ? $value : null;
    }

    public function sourceLocale(Connection $connection, array $tables): ?string
    {
        if (!isset($tables['configurations']) || !in_array('lang', $tables['configurations']['columns'], true)) {
            return null;
        }

        return ImportContext::normalizeLocale($connection->table('configurations')->value('lang'));
    }

    public function extensionNames(Connection $connection, array $tables): array
    {
        $plugins =
            isset($tables['plugins']) && in_array('name', $tables['plugins']['columns'], true)
                ? $connection->table('plugins')->pluck('name')->filter(fn ($name) => is_string($name))->values()->all()
                : [];
        $theme =
            isset($tables['configurations']) && in_array('theme', $tables['configurations']['columns'], true)
                ? $connection->table('configurations')->value('theme')
                : null;

        return ['plugin' => $plugins, 'theme' => is_string($theme) && $theme !== '' ? [$theme] : []];
    }

    /**
     * @throws JsonException
     */
    public function afterMapping(array $row, ImportContext $context, string $table): void
    {
        app(MediaReferences::class)->inspect($row, $context, $table);
    }
}
