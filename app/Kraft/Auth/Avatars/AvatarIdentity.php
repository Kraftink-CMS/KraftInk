<?php

namespace App\Kraft\Auth\Avatars;

final readonly class AvatarIdentity
{
    public function __construct(public string $game, public ?string $uuid, public ?string $username)
    {
    }
}
