<?php

namespace App\Kraft;

use Illuminate\Support\Facades\Crypt;

class Secrets
{
    public function __construct(private Settings $settings)
    {
    }

    public function get(string $key, string $default = ''): string
    {
        $value = $this->settings->get('secret.'.$key);

        return $value ? Crypt::decryptString($value) : $default;
    }

    public function set(string $key, string $value): void
    {
        $this->settings->set('secret.'.$key, Crypt::encryptString($value));
    }
}
