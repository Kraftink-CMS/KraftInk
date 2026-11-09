<?php

namespace App\Kraft\Games\Contracts;

use App\Kraft\Settings;
use App\Models\Accounts\GameIdentity;
use App\Models\Accounts\User;

interface GameAdapter
{
    public function id(): string;

    public function metadata(): array;

    public function settingsSchema(): array;

    public function identityRules(): array;

    public function normalizeUuid(string $uuid): string;

    public function assertCanAuthenticate(User $user, ?GameIdentity $identity, Settings $settings): void;

    public function validateSettings(User $user, array $settings, Settings $current): void;
}
