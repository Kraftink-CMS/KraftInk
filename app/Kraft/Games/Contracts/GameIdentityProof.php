<?php

namespace App\Kraft\Games\Contracts;

use App\Models\Servers\Server;

interface GameIdentityProof
{
    public function game(): string;

    public function source(): string;

    public function configuration(): ?string;

    public function linkedServer(int $id, bool $forUpdate = false): ?Server;
}
