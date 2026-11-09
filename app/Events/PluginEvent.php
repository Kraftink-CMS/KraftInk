<?php

namespace App\Events;

use JsonSerializable;

final readonly class PluginEvent implements JsonSerializable
{
    public function __construct(
        public string $id,
        public string $name,
        public int $version,
        public array $data,
        public string $occurredAt,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'version' => $this->version,
            'data' => $this->data,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
