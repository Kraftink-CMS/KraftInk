<?php

namespace App\Kraft\Auth\InGameSignIn;

use App\Kraft\Auth\AuthPolicy;
use App\Kraft\Auth\Captcha;
use App\Kraft\Games\Contracts\SupportsInGameSignIn;
use App\Kraft\Games\GameRegistry;
use App\Kraft\OAuth\OAuthChanges;
use App\Kraft\Settings;
use App\Models\Accounts\GameIdentity;
use App\Models\Accounts\User;
use App\Models\Servers\Server;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InGameSignInPolicy
{
    public function __construct(
        private readonly AuthPolicy $auth,
        private readonly GameRegistry $games,
        private readonly Settings $settings,
        private readonly Captcha $captcha,
    ) {
    }

    public function available(): bool
    {
        return $this->server() !== null;
    }

    public function captchaAction(): string
    {
        return $this->settings->get('registration.enabled', true) && $this->captcha->configuration('register') !== null
            ? 'register'
            : 'login';
    }

    public function registrationCaptchaFingerprint(): ?string
    {
        $configuration = $this->captcha->configuration('register');
        if ($configuration === null) {
            return null;
        }

        return hash('sha256', serialize([
            $configuration['id'],
            $configuration['site_key'],
            $this->settings->get('secret.captcha.'.$configuration['id']),
        ]));
    }

    public function canSignIn(User $user): bool
    {
        if (!$this->allowsUser($user)) {
            return false;
        }
        $server = $this->server();
        $identity = $server ? $this->games->identityFor($user, $server->game) : null;

        return $identity?->verified_at !== null &&
            $this->adapter($identity->game)?->supportsInGameSignInIdentity($identity->uuid) === true;
    }

    public function allowsUser(User $user): bool
    {
        return $user->canAuthenticateAsMember() && !$user->registration_pending && !$user->force_password_reset;
    }

    public function withStableConfiguration(Closure $operation): mixed
    {
        try {
            return Cache::store('database')->lock('in-game-sign-in.settings', 30)->block(3, function () use ($operation): mixed {
                $this->settings->refresh();

                return $operation();
            });
        } catch (LockTimeoutException) {
            throw new HttpException(503, __('in-game-sign-in.busy'));
        }
    }

    public function ensureKeepsSignIn(string $nextGame, array $nextSettings): void
    {
        $game = $this->games->activeId();
        $adapter = $game ? $this->adapter($game) : null;
        if (!$adapter || !$this->auth->allowsLocal() || $this->settings->get('games.'.$game.'.in_game_sign_in') !== 'enabled') {
            return;
        }
        $remainsEnabled = $game === $nextGame && ($nextSettings['in_game_sign_in'] ?? 'disabled') === 'enabled';
        $field = $game === $nextGame ? 'game_settings.'.$game.'.in_game_sign_in' : 'default_game';
        $this->ensureAlternatives(
            $game,
            $field,
            fn (GameIdentity $identity): bool => $adapter->supportsInGameSignInIdentity($identity->uuid) &&
                (!$remainsEnabled || !$adapter->supportsInGameSignInIdentity($identity->uuid, $nextSettings)),
        );
    }

    public function ensureServerKeepsSignIn(int $server, ?array $next = null): void
    {
        $game = $this->games->activeId();
        $adapter = $game ? $this->adapter($game) : null;
        if (
            !$adapter || !$this->auth->allowsLocal() ||
            $this->settings->get('games.'.$game.'.in_game_sign_in') !== 'enabled' ||
            (int) $this->settings->get('games.'.$game.'.in_game_sign_in_server') !== $server ||
            ($next && $next['enabled'] && $next['connection'] === 'kraftlink' && $next['game'] === $game)
        ) {
            return;
        }

        $this->ensureAlternatives(
            $game,
            'server',
            fn (GameIdentity $identity): bool => $adapter->supportsInGameSignInIdentity($identity->uuid),
        );
    }

    private function ensureAlternatives(string $game, string $field, Closure $losesSignIn): void
    {
        $alternatives = app(OAuthChanges::class);
        $identities = GameIdentity::query()->where('game', $game)->whereNotNull('verified_at')
            ->whereHas('user', fn (Builder $users) => $users->members()->withoutActiveBan()
                ->where(fn (Builder $local) => $local->where('local_login_enabled', false)
                    ->orWhereNull('password')->orWhere('password', ''))
                ->where('import_locked', false)->whereNull('anonymized_at'))
            ->with('user')->lazyById();
        foreach ($identities as $identity) {
            $user = $identity->user;
            if ($user && $losesSignIn($identity) && !$alternatives->usable($user)) {
                throw ValidationException::withMessages([$field => __('in-game-sign-in.last_method')]);
            }
        }
    }

    public function server(): ?Server
    {
        $game = $this->games->activeId();
        if (
            !$game ||
            !$this->auth->allowsLocal() ||
            $this->settings->get('games.'.$game.'.in_game_sign_in') !== 'enabled' ||
            !$this->adapter($game)
        ) {
            return null;
        }

        return $this->findServer($game, $this->settings->get('games.'.$game.'.in_game_sign_in_server'));
    }

    public function trusts(Server $server): bool
    {
        $selected = $this->server();

        return $selected !== null && $selected->is($server) && hash_equals($selected->token_hash, $server->token_hash ?? '');
    }

    public function adapter(string $game): ?SupportsInGameSignIn
    {
        $adapter = $this->games->find($game);

        return $adapter instanceof SupportsInGameSignIn ? $adapter : null;
    }

    public function findServer(string $game, mixed $id): ?Server
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : Server::linkedKraftLink($game)->find($id);
    }

    public function serverOptions(string $game): array
    {
        return Server::linkedKraftLink($game)->orderBy('name')->orderBy('id')->pluck('name', 'id')->all();
    }
}
