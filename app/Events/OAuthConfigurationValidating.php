<?php

namespace App\Events;

class OAuthConfigurationValidating
{
    public function __construct(public array $providers)
    {
    }
}
