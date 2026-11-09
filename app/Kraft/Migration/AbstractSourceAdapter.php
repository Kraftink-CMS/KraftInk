<?php

namespace App\Kraft\Migration;

use App\Kraft\Migration\Contracts\SourceAdapter;
use App\Kraft\Games\GameRegistry;
use Illuminate\Database\Connection;

abstract class AbstractSourceAdapter implements SourceAdapter
{
    public function userTable(): ?string
    {
        return 'users';
    }

    public function sourceLocale(Connection $connection, array $tables): ?string
    {
        return null;
    }

    public function extensionNames(Connection $connection, array $tables): array
    {
        return ['plugin' => [], 'theme' => []];
    }

    public function afterMapping(array $row, ImportContext $context, string $table): void
    {
    }

    protected function normalizeIdentities(array $identities): array
    {
        $games = app(GameRegistry::class);

        return array_map(
            fn (array $identity) => array_replace($identity, [
                'uuid' => $games->get($identity['game'])->normalizeUuid($identity['uuid']),
            ]),
            $identities,
        );
    }
}
