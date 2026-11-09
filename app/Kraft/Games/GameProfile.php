<?php

namespace App\Kraft\Games;

readonly class GameProfile
{
    public function __construct(public string $uuid, public string $username, public ?string $avatarUrl = null)
    {
    }
}
