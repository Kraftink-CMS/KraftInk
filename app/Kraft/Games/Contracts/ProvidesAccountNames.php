<?php

namespace App\Kraft\Games\Contracts;

interface ProvidesAccountNames
{
    public function accountNamePresentation(): array;

    public function normalizeAccountName(string $name): string;
}
