<?php

namespace App\Kraft\Games\Contracts;

interface ProvidesCommandIdentity
{
    public const array COMMAND_TOKENS = ['player', 'uuid'];

    public function commandIdentity(string $uuid, ?string $username): array;
}
