<?php

namespace App\Kraft\Games\Contracts;

use App\Kraft\Games\GameProfile;

interface ResolvesPublicProfiles
{
    public function publicProfile(string $username): GameProfile;
}
