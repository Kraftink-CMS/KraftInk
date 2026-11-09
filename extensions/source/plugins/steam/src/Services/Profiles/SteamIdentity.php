<?php

namespace KraftInk\Integrations\Steam\Services\Profiles;

use Illuminate\Validation\ValidationException;

class SteamIdentity
{
    public static function normalize(string $value): string
    {
        if (preg_match('/^steam:([0-9a-f]{15,16})$/iD', $value, $matches)) {
            $value = (string) hexdec($matches[1]);
        } elseif (preg_match('/^STEAM_[0-5]:([01]):([0-9]{1,10})$/D', $value, $matches)) {
            $value = (string) (76561197960265728 + (int) $matches[2] * 2 + (int) $matches[1]);
        } elseif (preg_match('/^\\[U:1:([0-9]{1,10})\\]$/D', $value, $matches)) {
            $value = (string) (76561197960265728 + (int) $matches[1]);
        }
        if (
            !preg_match('/^[0-9]{17}$/D', $value) ||
            strcmp($value, '76561197960265728') < 0 ||
            strcmp($value, '76561202255233023') > 0
        ) {
            throw ValidationException::withMessages(['uuid' => __('steam::messages.invalid_id')]);
        }

        return $value;
    }
}
