<?php

namespace App\Kraft\Games\Contracts;

interface SupportsInGameSignIn
{
    public function inGameSignInName(string $name): ?string;

    public function inGameSignInProfile(string $uuid, string $name, string $platform): ?array;

    public function supportsInGameSignInIdentity(string $uuid, array $settings = []): bool;
}
