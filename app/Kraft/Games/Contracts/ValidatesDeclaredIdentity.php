<?php

namespace App\Kraft\Games\Contracts;

interface ValidatesDeclaredIdentity
{
    public function declaredIdentity(array $data): array;
}
