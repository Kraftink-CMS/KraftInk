<?php

namespace KraftInk\Games\Minecraft\Services\Auth;

use App\Kraft\Auth\AuthPolicy;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Settings;
use App\Models\Servers\Server;
use Illuminate\Http\Request;

class AuthMeRegistrationPolicy
{
    public function __construct(private readonly Settings $settings, private readonly GameRegistry $games)
    {
    }

    public function allowsTransport(Request $request): bool
    {
        $loopback = ['127.0.0.1', '::1'];

        return $request->isSecure() || (
            in_array($request->server('REMOTE_ADDR'), $loopback, true) &&
            in_array(trim($request->getHost(), '[]'), ['localhost', ...$loopback], true)
        );
    }

    public function trusts(Server $server): bool
    {
        if (
            $this->games->activeId() !== 'minecraft' ||
            $this->settings->get('games.minecraft.authme_integration') !== 'enabled' ||
            !$this->settings->get('registration.enabled', true) ||
            !app(AuthPolicy::class)->allowsLocal()
        ) {
            return false;
        }
        $selected = $this->findServer($this->settings->get('games.minecraft.authme_server'));

        return $selected !== null && $selected->is($server) && hash_equals($selected->token_hash, $server->token_hash ?? '');
    }

    public function allowsOfflineRegistration(): bool
    {
        return $this->settings->get('games.minecraft.authentication', 'open') === 'open';
    }

    public function findServer(mixed $id): ?Server
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : Server::linkedKraftLink('minecraft')->find($id);
    }

    public function serverOptions(): array
    {
        return Server::linkedKraftLink('minecraft')->orderBy('name')->orderBy('id')->pluck('name', 'id')->all();
    }
}
