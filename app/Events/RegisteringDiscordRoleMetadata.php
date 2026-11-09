<?php

namespace App\Events;

use App\Kraft\OAuth\Discord\RoleMetadata;

class RegisteringDiscordRoleMetadata
{
    public function __construct(public RoleMetadata $metadata)
    {
    }
}
