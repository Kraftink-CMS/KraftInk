<?php

namespace App\Events;

use InvalidArgumentException;

class PruningData
{
    private array $counts = [];

    public function __construct(public bool $dryRun)
    {
    }

    public function add(string $scope, int $count): void
    {
        if ($scope === '' || $count < 0) {
            throw new InvalidArgumentException('Invalid retention count.');
        }
        $this->counts[$scope] = ($this->counts[$scope] ?? 0) + $count;
    }

    public function counts(): array
    {
        return $this->counts;
    }
}
