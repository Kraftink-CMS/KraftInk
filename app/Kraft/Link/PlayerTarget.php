<?php

namespace App\Kraft\Link;

use App\Kraft\Games\PlayerUuid;

final readonly class PlayerTarget
{
    public function __construct(public string $value)
    {
    }

    public function uuid(): ?string
    {
        return PlayerUuid::compact($this->value);
    }

    public function canonical(): string
    {
        return $this->uuid() ?? $this->value;
    }

    public function name(): ?string
    {
        return $this->uuid() === null ? $this->value : null;
    }

    public function matches(array $player): bool
    {
        $uuid = $this->uuid();
        if ($uuid !== null) {
            return is_string($player['uuid'] ?? null) && $uuid === new self($player['uuid'])->uuid();
        }

        return is_string($player['name'] ?? null) && mb_strtolower($this->value) === mb_strtolower($player['name']);
    }
}
