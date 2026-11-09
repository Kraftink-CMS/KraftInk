<?php

namespace App\Kraft;

use KraftSigningKeys;
use RuntimeException;

class SigningKeys
{
    public function publicKey(mixed $id): string
    {
        if (!class_exists(KraftSigningKeys::class, false)) {
            require_once base_path('updater/signing.php');
        }
        $registry = config('signing.public_keys', []);
        if (!is_array($registry)) {
            throw new RuntimeException('The public signing key registry is invalid.');
        }

        return KraftSigningKeys::resolve($id, $registry);
    }
}
