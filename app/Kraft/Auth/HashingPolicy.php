<?php

namespace App\Kraft\Auth;

use App\Kraft\Settings;
use SensitiveParameter;
use Illuminate\Hashing\HashManager;

class HashingPolicy extends HashManager
{
    public static function algorithms(): array
    {
        $algorithms = ['bcrypt' => 'Bcrypt'];
        if (in_array('argon2i', password_algos(), true)) {
            $algorithms['argon'] = 'Argon2i';
        }
        if (in_array('argon2id', password_algos(), true)) {
            $algorithms['argon2id'] = 'Argon2id';
        }

        return $algorithms;
    }

    public function getDefaultDriver(): string
    {
        $driver = $this->container->make(Settings::class)->get('auth.hash_driver', parent::getDefaultDriver());

        return array_key_exists($driver, self::algorithms()) ? $driver : 'bcrypt';
    }

    /** @param string $value */
    public function check(#[SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        if (
            !is_string($hashedValue) ||
            !in_array(password_get_info($hashedValue)['algoName'], ['bcrypt', 'argon2i', 'argon2id'], true)
        ) {
            return false;
        }

        return password_verify($value, $hashedValue);
    }

    /** @param string $value */
    public function verifyConfiguration($value): bool
    {
        $driver =
            ['bcrypt' => 'bcrypt', 'argon2i' => 'argon', 'argon2id' => 'argon2id'][
                password_get_info($value)['algoName']
            ] ?? null;

        return $driver &&
            array_key_exists($driver, self::algorithms()) &&
            $this->driver($driver)->verifyConfiguration($value);
    }
}
