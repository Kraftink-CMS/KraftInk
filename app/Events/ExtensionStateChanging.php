<?php

namespace App\Events;

class ExtensionStateChanging
{
    public function __construct(public array $manifest, public bool $enabled)
    {
    }
}
