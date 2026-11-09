<?php

namespace App\Kraft\Games\Contracts;

interface ProvidesGuestIdentity
{
    public function guestIdentity(string $input): array;
}
