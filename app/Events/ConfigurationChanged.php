<?php

namespace App\Events;

class ConfigurationChanged
{
    public function __construct(public string $key)
    {
    }
}
