<?php

namespace App\Kraft\Site;

use App\Kraft\Auth\AccountPolicy;
use App\Kraft\Auth\AuthPolicy;
use App\Kraft\Features;
use App\Kraft\Games\GameRegistry;
use App\Kraft\LocalePolicy;
use App\Kraft\Navigation;
use App\Kraft\OAuth\OAuthRegistry;
use App\Kraft\OAuth\Discord\RoleConnections;
use App\Kraft\Settings;
use App\Models\Content\ContentEntry;
use App\Models\Accounts\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

final readonly class ViewCapabilities
{
    public function __construct(
        public bool $hasCustomHeader,
        public bool $hasCustomFooter,
        public bool $hasRules,
        public bool $hasPrivacy,
        public bool $canChooseLocale,
        public bool $canAccessAdmin,
        public bool $canRegister,
        public bool $canLoginLocally,
        public bool $hasNews,
        public bool $canManageConnections,
        public bool $canManageTokens,
        public bool $canManageGames,
        public bool $canManageDiscordRoles,
        public bool $canDeleteAccount,
        public bool $canListSessions,
        public bool $canTransferWallet,
        public bool $canEditUsername,
        public bool $canDisableTwoFactor,
        public bool $requiresEmailConfirmation,
        private ?User $viewer,
        private array $extensions,
    ) {
    }

    public static function forRequest(Request $request, bool $withExtensions = true): self
    {
        return self::forUser($request->user(), $request, $withExtensions);
    }

    public static function forUser(?User $user, ?Request $request = null, bool $withExtensions = true): self
    {
        /** @var Settings $settings */
        $settings = app(Settings::class);
        $auth = app(AuthPolicy::class);
        $accounts = app(AccountPolicy::class);
        $navigation = app(Navigation::class);
        $legalPages = ContentEntry::published()
            ->visibleTo($user)
            ->where('kind', 'page')
            ->whereIn('slug', ['rules', 'privacy'])
            ->pluck('slug')
            ->all();
        $canAccessAdmin = $user?->hasPermission('admin.access') ?? false;
        $discordRoles =
            $user &&
            (app(RoleConnections::class)->ready() ||
                $user->discordRoleConnections()->exists() ||
                $user->discordRoleRevocations()->where('state', '!=', 'completed')->exists());
        $oldEmail = $user ? $request?->old('email', $user->email) : null;

        return new self(
            hasCustomHeader: $navigation->configured('header'),
            hasCustomFooter: $navigation->configured('footer'),
            hasRules: in_array('rules', $legalPages, true),
            hasPrivacy: in_array('privacy', $legalPages, true),
            canChooseLocale: app(LocalePolicy::class)->allowsChoice(),
            canAccessAdmin: $canAccessAdmin,
            canRegister: $settings->get('registration.enabled', true) && $auth->allowsLocal(),
            canLoginLocally: $auth->allowsLocal(),
            hasNews: ContentEntry::published()->visibleTo($user)->where('kind', 'news')->exists(),
            canManageConnections: $user &&
                (app(OAuthRegistry::class)->enabled() !== [] || $user->oauthAccounts()->exists() || $discordRoles),
            canManageTokens: $user &&
                (($settings->get('auth.api.enabled', false) && $auth->allowsLocal()) ||
                    $user->apiAccessTokens()->active()->exists()),
            canManageGames: $user && app(GameRegistry::class)->accountGames() !== [],
            canManageDiscordRoles: $discordRoles,
            canDeleteAccount: $user && $accounts->allows('deletion'),
            canListSessions: $user && config('session.driver') === 'database',
            canTransferWallet: $user && $settings->get('wallet.transfers', false),
            canEditUsername: $user && $accounts->allows('username'),
            canDisableTwoFactor: $user && (!$canAccessAdmin || !$settings->get('security.admin_two_factor', false)),
            requiresEmailConfirmation: $user &&
                ($user->local_login_enabled || $user->two_factor_confirmed_at) &&
                is_string($oldEmail) &&
                mb_strtolower(trim($oldEmail)) !== mb_strtolower($user->email ?? ''),
            viewer: $user,
            extensions: $withExtensions ? app(Features::class)->all($user) : [],
        );
    }

    public function extension(string $feature): bool
    {
        return $this->extensions[$feature] ?? false;
    }

    public function allowsNative(string $capability): bool
    {
        return (get_object_vars($this)[$capability] ?? false) === true;
    }

    public function routeAvailable(string $route): bool
    {
        $definition = Route::getRoutes()->getByName($route);
        if (!$definition || (in_array($route, ['register', 'register.store'], true) && !$this->canRegister)) {
            return false;
        }
        foreach ($definition->gatherMiddleware() as $middleware) {
            if (str_starts_with($middleware, 'capability:') && !str_starts_with($middleware, 'capability:extension,')) {
                $capability = explode(',', substr($middleware, 11))[0];
                if (!$this->allowsNative($capability)) {
                    return false;
                }
            }
        }

        return app(Features::class)->routeAvailable($route, $this->viewer, $this->extensions);
    }
}
