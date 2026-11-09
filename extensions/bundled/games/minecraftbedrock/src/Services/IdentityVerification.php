<?php

namespace KraftInk\Games\MinecraftBedrock\Services;

use App\Kraft\Games\Contracts\GameIdentityProof;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Games\IdentityVerificationCommand;
use App\Kraft\Settings;
use App\Models\Servers\Server;
use Illuminate\Database\Eloquent\Builder;

class IdentityVerification implements GameIdentityProof
{
    public function __construct(private readonly Settings $settings, private readonly GameRegistry $games)
    {
    }

    public function game(): string
    {
        return 'minecraftbedrock';
    }

    public function source(): string
    {
        return 'bedrock_xbox_session';
    }

    public function configuration(): ?string
    {
        if ($this->games->activeId() !== $this->game() ||
            $this->settings->get('games.minecraftbedrock.identity_verification', 'disabled') !== 'enabled') {
            return null;
        }

        $id = filter_var($this->settings->get('games.minecraftbedrock.identity_verification_server'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $id === false ? null : (string) $id;
    }

    public function linkedServer(int $id, bool $forUpdate = false): ?Server
    {
        return (string) $id === $this->configuration()
            ? $this->eligibleServers()->when($forUpdate, fn (Builder $query) => $query->lockForUpdate())->find($id)
            : null;
    }

    public function selectedServer(): ?Server
    {
        $configuration = $this->configuration();
        $server = $configuration === null ? null : $this->linkedServer((int) $configuration);

        return $server?->hasFreshStatus() ? $server : null;
    }

    public function ready(): bool
    {
        return $this->selectedServer() !== null;
    }

    public function serverOptions(): array
    {
        return $this->eligibleServers()->orderBy('name')->orderBy('id')->pluck('name', 'id')->all();
    }

    public function command(string $code): string
    {
        return IdentityVerificationCommand::render($this->settings->get('games.minecraftbedrock.identity_verification_command'), $code);
    }

    private function eligibleServers(): Builder
    {
        return Server::linkedKraftLink($this->game())->where('status->connector_platform', 'nukkit')
            ->where('status->identity_verification', true);
    }
}
