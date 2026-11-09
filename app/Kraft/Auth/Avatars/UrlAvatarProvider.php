<?php

namespace App\Kraft\Auth\Avatars;

use App\Kraft\Games\PlayerUuid;
use InvalidArgumentException;

class UrlAvatarProvider implements AvatarProvider
{
    public function __construct(
        private string $game,
        private string $providerId,
        private array $providerMetadata,
        private string $template,
        private ?string $usernamePattern = null,
    ) {
        $url = str_replace('{identity}', 'identity', $template);
        if (
            !is_string($providerMetadata['name'] ?? null) ||
            trim($providerMetadata['name']) === '' ||
            !is_string($providerMetadata['description'] ?? null) ||
            array_filter($providerMetadata, fn ($value) => !is_string($value)) !== [] ||
            substr_count($template, '{identity}') !== 1 ||
            !filter_var($url, FILTER_VALIDATE_URL) ||
            parse_url($url, PHP_URL_SCHEME) !== 'https' ||
            str_contains(parse_url($template, PHP_URL_HOST) ?? '', '{identity}') ||
            parse_url($url, PHP_URL_USER) !== null ||
            parse_url($url, PHP_URL_PASS) !== null ||
            preg_match('/[\x00-\x20"<>\\\\]/', $template) ||
            ($usernamePattern !== null && @preg_match($usernamePattern, '') === false)
        ) {
            throw new InvalidArgumentException('Invalid URL avatar provider definition.');
        }
    }

    public function id(): string
    {
        return $this->providerId;
    }

    public function metadata(): array
    {
        return $this->providerMetadata;
    }

    public function url(AvatarIdentity $identity): ?string
    {
        if ($identity->game !== $this->game) {
            return null;
        }
        $identifier = $identity->uuid === null ? null : PlayerUuid::compact($identity->uuid);
        if (
            $identifier === null &&
            $this->usernamePattern !== null &&
            $identity->username !== null &&
            preg_match($this->usernamePattern, $identity->username)
        ) {
            $identifier = $identity->username;
        }

        return $identifier === null ? null : str_replace('{identity}', rawurlencode($identifier), $this->template);
    }
}
