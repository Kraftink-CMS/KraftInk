<?php

namespace KraftInk\Games\Minecraft\Services\Auth;

use App\Kraft\Auth\AccountIdentity;
use App\Kraft\Auth\AccountPolicy;
use App\Kraft\Auth\InGameSignIn\InGameSignInPolicy;
use App\Kraft\Games\GameRegistry;
use App\Models\Accounts\GameIdentity;
use App\Models\Accounts\User;
use App\Models\Servers\Server;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use KraftInk\Games\Minecraft\Adapters\MinecraftAdapter;
use SensitiveParameter;

class AuthMeRegistrations
{
    public const string SOURCE = 'kraftlink_authme_registration';

    public function __construct(
        private readonly AuthMeRegistrationPolicy $policy,
        private readonly GameRegistry $games,
        private readonly AccountPolicy $accounts,
    ) {
    }

    public function register(Server $server, string $uuid, string $username, #[SensitiveParameter] string $password, int $registeredAt): void
    {
        $uuid = MinecraftAdapter::parseUuid($uuid);
        $offline = $uuid !== null && $uuid === self::offlineUuid($username);
        abort_unless($offline || MinecraftAdapter::isVerifiedJavaUuid($uuid), 422);
        if (str_contains($password, "\0")) {
            throw ValidationException::withMessages(['password' => __('validation.regex', ['attribute' => 'password'])]);
        }
        app(InGameSignInPolicy::class)->withStableConfiguration(function () use ($server, $uuid, $username, $password, $registeredAt, $offline): void {
            abort_unless($this->policy->trusts($server) && (!$offline || $this->policy->allowsOfflineRegistration()), 403);
            if (Hash::getDefaultDriver() === 'bcrypt' && strlen($password) > 72) {
                throw ValidationException::withMessages(['password' => __('site.auth.password.too_long')]);
            }
            try {
                DB::transaction(function () use ($server, $uuid, $username, $password, $registeredAt): void {
                    abort_if(
                        User::query()->whereRaw(AccountIdentity::expression().' = ?', [strtolower($username)])->exists() ||
                        GameIdentity::query()->where('game', 'minecraft')->where('uuid', $uuid)->exists(),
                        409,
                    );
                    $requiresEmail = $this->accounts->requiresRegistrationEmail();
                    $user = new User(['username' => $username, 'password' => Hash::make($password), 'locale' => app()->getLocale()]);
                    $user->forceFill(['registration_pending' => $requiresEmail])->save();
                    $this->games->verifyIdentity(
                        $user,
                        'minecraft',
                        ['uuid' => $uuid, 'username' => $username],
                        self::SOURCE,
                        replaceUnverified: false,
                        metadata: ['server_id' => $server->id, 'authme_registered_at' => $registeredAt],
                    );
                    if (!$requiresEmail) {
                        DB::afterCommit(fn () => event(new Registered($user)));
                    }
                });
            } catch (UniqueConstraintViolationException) {
                abort(409);
            }
        });
    }

    private static function offlineUuid(string $username): string
    {
        $uuid = md5('OfflinePlayer:'.$username);
        $uuid[12] = '3';
        $uuid[16] = dechex((hexdec($uuid[16]) & 3) | 8);

        return $uuid;
    }
}
