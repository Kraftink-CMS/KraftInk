<?php

namespace KraftInk\Games\Minecraft\Services\Auth;

use App\Kraft\Games\GameRegistry;
use App\Kraft\Settings;
use App\Models\Servers\Server;
use Illuminate\Database\Eloquent\Builder;
use KraftInk\Games\Minecraft\Services\Profiles\FloodgateProfiles;

class BedrockVerificationPolicy
{
    public const string DEFAULT_COMMAND = 'link';

    private const array COMMANDS = ['link' => '/link', 'kraftlink' => '/kraftlink link'];

    public function __construct(
        private readonly Settings $settings,
        private readonly GameRegistry $games,
        private readonly FloodgateProfiles $profiles,
    ) {
    }

    public function enabled(): bool
    {
        return $this->games->activeId() === 'minecraft' &&
            $this->settings->get('games.minecraft.authentication', 'open') === 'premium' &&
            $this->settings->get('games.minecraft.premium_bedrock', 'disabled') === 'enabled' &&
            $this->profiles->enabled();
    }

    public function available(): bool
    {
        return $this->enabled() && $this->hasLinkedServers();
    }

    public function hasLinkedServers(): bool
    {
        return Server::linkedKraftLink('minecraft')->exists();
    }

    public function linkedServer(int $id, bool $forUpdate = false): ?Server
    {
        return Server::linkedKraftLink('minecraft')->when($forUpdate, fn (Builder $query) => $query->lockForUpdate())->find($id);
    }

    public function command(string $code): string
    {
        $choice = $this->settings->get('games.minecraft.bedrock_verification_command');
        $prefix = is_string($choice) ? self::COMMANDS[$choice] ?? null : null;

        return ($prefix ?? self::COMMANDS[self::DEFAULT_COMMAND]).' '.$code;
    }
}
