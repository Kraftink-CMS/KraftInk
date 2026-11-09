<?php

namespace App\Kraft\Games;

final class IdentityVerificationCommand
{
    private const string DEFAULT = 'link';

    private const array COMMANDS = ['link' => '/link', 'kraftlink' => '/kraftlink link'];

    public static function field(): array
    {
        return [
            'label' => 'site.games.verification_command',
            'description' => 'site.games.verification_command_help',
            'default' => self::DEFAULT,
            'required' => false,
            'options' => array_map(fn (string $command): string => $command.' CODE', self::COMMANDS),
        ];
    }

    public static function render(mixed $choice, string $code): string
    {
        $command = is_string($choice) ? self::COMMANDS[$choice] ?? null : null;

        return ($command ?? self::COMMANDS[self::DEFAULT]).' '.$code;
    }
}
