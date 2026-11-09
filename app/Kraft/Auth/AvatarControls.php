<?php

namespace App\Kraft\Auth;

final readonly class AvatarControls
{
    public function __construct(public string $url, public bool $canUpload, public bool $canDelete)
    {
    }
}
