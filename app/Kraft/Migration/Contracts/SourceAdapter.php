<?php

namespace App\Kraft\Migration\Contracts;

use App\Kraft\Migration\CoreMappings;
use App\Kraft\Migration\ImportContext;
use App\Kraft\Migration\MappingRegistry;
use Illuminate\Database\Connection;

interface SourceAdapter
{
    public function id(): string;

    public function requirements(): array;

    public function userTable(): ?string;

    public function roleTable(): string;

    public function newsTable(): string;

    public function user(array $row, ImportContext $context): array;

    public function sourceLocale(Connection $connection, array $tables): ?string;

    public function extensionNames(Connection $connection, array $tables): array;

    public function afterMapping(array $row, ImportContext $context, string $table): void;

    public function registerMappings(MappingRegistry $registry, CoreMappings $core): void;
}
