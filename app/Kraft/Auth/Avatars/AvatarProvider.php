<?php

namespace App\Kraft\Auth\Avatars;

interface AvatarProvider
{
    public function id(): string;

    public function metadata(): array;

    public function url(AvatarIdentity $identity): ?string;
}
