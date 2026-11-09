<?php

namespace App\Kraft\Link\Accounts;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Models\Accounts\ApiAccessToken;
use App\Models\Setting;
use App\Models\Servers\LinkAccountOperation;
use App\Kraft\Audit;
use App\Kraft\Auth\AccountIdentity;
use App\Kraft\Auth\AccountPolicy;
use App\Kraft\Auth\AuthPolicy;
use App\Kraft\Auth\Captcha;
use App\Kraft\Auth\DefaultRole;
use App\Kraft\Games\GameRegistry;
use App\Kraft\LocalePolicy;
use App\Kraft\Settings;
use App\Kraft\Wallet\Money;
use App\Kraft\Wallet\Wallet;
use App\Models\Accounts\GameIdentity;
use App\Models\Servers\LinkAccountToken;
use App\Models\Accounts\Role;
use App\Models\Servers\Server;
use App\Models\Accounts\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;

class AccountOperations
{
    public function __construct(
        private AccountTokens $tokens,
        private AccountAccess $access,
        private GameRegistry $games,
        private Settings $settings,
    ) {
    }

    /**
     * @throws JsonException
     */
    public function profile(string $token, string $uuid): array
    {
        return $this->tokens->with($token, 'profile:read', function (Server $server) use ($uuid) {
            [$user, $identity] = $this->access->target($server, $uuid);
            $this->access->verifiedEmail($user);

            return $this->publicProfile($user, $identity);
        });
    }

    /**
     * @throws JsonException
     */
    public function balance(string $token, string $uuid): array
    {
        return $this->tokens->with($token, 'wallet:read', function (Server $server) use ($uuid) {
            [$user] = $this->access->target($server, $uuid);
            $this->access->verifiedEmail($user);

            return [
                'balance_minor' => app(Wallet::class)->balance($user),
                'currency' => app(Wallet::class)->currency(),
            ];
        });
    }

    /**
     * @throws JsonException
     */
    public function policy(string $token): array
    {
        return $this->tokens->with($token, 'account:create', fn (Server $server) => $this->registrationPolicy($server));
    }

    /**
     * @throws JsonException
     */
    public function register(string $token, Request $request): array
    {
        return $this->tokens->with($token, 'account:create', function (Server $server, User $actor) use ($request) {
            if (!$this->settings->get('registration.enabled', true) || !app(AuthPolicy::class)->allowsLocal()) {
                throw new HttpException(403);
            }
            AccountIdentity::normalize($request);
            $data = $request->all();
            $policy = $this->registrationPolicy($server);
            $email = $data['email'] ?? null;
            if (is_string($email)) {
                $email = mb_strtolower(trim($email));
            }
            $data['email'] = $email === '' ? null : $email;
            $validator = Validator::make($data, [
                'username' => AccountIdentity::usernameRules(),
                'email' => [$policy['email_required'] ? 'required' : 'nullable', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'confirmed', app(AuthPolicy::class)->password()],
                'terms' => 'required|accepted',
                'terms_version' => ['required', Rule::in([$policy['terms_version']])],
                'identity' => 'required|array:uuid,username',
                'roles' => 'prohibited',
                'permissions' => 'prohibited',
                'email_verified_at' => 'prohibited',
            ]);
            $data = $validator->validate();
            $identityValidator = Validator::make($data['identity'], $this->games->get($server->game)->identityRules());
            if ($identityValidator->fails()) {
                throw new ValidationException($identityValidator);
            }
            $identity = $identityValidator->validated();
            $default = Role::query()->where('system_key', 'member')->lockForUpdate()->first();
            if ($default && !app(DefaultRole::class)->safe($default)) {
                throw new HttpException(403, __('admin.servers.accounts.unsafe_default_role'));
            }
            app(Captcha::class)->verify($request, 'register');
            $user = User::query()->create([
                'username' => $data['username'],
                'email' => $data['email'],
                'password' => $data['password'],
                'locale' => app(LocalePolicy::class)->defaultLocale(),
            ]);
            $gameIdentity = $this->games->declareIdentity($user, $server->game, $identity);
            $pendingGame = false;
            try {
                $this->games->assertUserCanAuthenticate($user);
            } catch (ValidationException) {
                $pendingGame = true;
            }
            Audit::record(
                'link.account_registered',
                [
                    'server' => $server->id,
                    'id' => $user->id,
                    'terms_version' => $policy['terms_version'],
                    'consented_at' => now()->toIso8601String(),
                ],
                $actor,
            );
            DB::afterCommit(fn () => event(new Registered($user)));

            return $this->publicProfile($user, $gameIdentity) + [
                'email_verified' => false,
                'email_verification_required' => app(AccountPolicy::class)->requiresVerifiedEmail(),
                'game_verification_required' => $pendingGame,
                'continue_url' => url('/login'),
            ];
        });
    }

    /**
     * @throws JsonException
     */
    public function changeEmail(string $token, string $uuid, array $data): array
    {
        return $this->tokens->with($token, 'account:email', function (Server $server, User $actor) use ($uuid, $data) {
            [$user] = $this->access->target($server, $uuid);
            $data['email'] = mb_strtolower(trim((string) ($data['email'] ?? '')));
            $validator = Validator::make($data, [
                'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
                'current_password' => 'required|string|max:1024',
                'code' => 'nullable|string|max:32',
            ]);
            $data = $validator->validate();
            $user = $this->access->proof($user, $data);
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->forceFill(['email' => $data['email'], 'email_verified_at' => null])->save();
            $this->invalidate($user);
            Audit::record('link.account_email_changed', ['server' => $server->id, 'id' => $user->id], $actor);
            DB::afterCommit(fn () => $user->sendEmailVerificationNotification());

            return ['updated' => true, 'email_verification_required' => true];
        });
    }

    /**
     * @throws JsonException
     */
    public function changePassword(string $token, string $uuid, array $data): array
    {
        return $this->tokens->with($token, 'account:password', function (Server $server, User $actor) use (
            $uuid,
            $data,
        ) {
            [$user] = $this->access->target($server, $uuid);
            $this->access->verifiedEmail($user);
            $validator = Validator::make($data, [
                'password' => ['required', 'confirmed', app(AuthPolicy::class)->password()],
                'current_password' => 'required|string|max:1024',
                'code' => 'nullable|string|max:32',
            ]);
            $data = $validator->validate();
            $user = $this->access->proof($user, $data);
            $user->forceFill(['password' => $data['password']])->save();
            $this->invalidate($user);
            Audit::record('link.account_password_changed', ['server' => $server->id, 'id' => $user->id], $actor);

            return ['updated' => true];
        });
    }

    /**
     * @throws JsonException
     */
    public function adjust(string $token, string $uuid, string $kind, array $data): array
    {
        if (!in_array($kind, ['credit', 'debit', 'set'], true)) {
            abort(404);
        }
        $validator = Validator::make($data, [
            'amount_minor' => [
                'bail',
                'required',
                function ($attribute, $value, $fail) {
                    if (!is_int($value) && !(is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,15})$/D', $value))) {
                        $fail(__('site.account.wallet.invalid_amount'));
                    }
                },
                'integer',
                'min:'.($kind === 'set' ? 0 : 1),
                'max:'.Money::MaxBalance,
            ],
            'currency' => 'required|string|regex:/^[A-Z]{3}$/D',
            'request_id' => 'required|uuid',
        ]);
        $data = $validator->validate();

        return $this->tokens->with($token, 'wallet:'.$kind, function (
            Server $server,
            User $actor,
            LinkAccountToken $grant,
        ) use ($uuid, $kind, $data) {
            [$user] = $this->access->target($server, $uuid);
            $this->access->verifiedEmail($user);
            $wallet = app(Wallet::class);
            $key = 'link:'.$server->id.':'.$data['request_id'];
            $hash = hash(
                'sha256',
                json_encode(
                    [$server->id, $user->id, $kind, (int) $data['amount_minor'], $data['currency']],
                    JSON_THROW_ON_ERROR,
                ),
            );
            $existing = LinkAccountOperation::query()->where('operation_key', $key)->lockForUpdate()->first();
            if ($existing) {
                if (!hash_equals($existing->payload_hash, $hash)) {
                    throw new HttpException(409, __('admin.servers.accounts.operation_conflict'));
                }

                return $existing->result;
            }
            $currency = Setting::query()->where('key', 'wallet.currency')->sharedLock()->first();
            if (!$currency || $currency->value !== $data['currency'] || $data['currency'] !== $wallet->currency()) {
                throw new HttpException(409, __('site.account.wallet.currency_changed'));
            }
            $before = $wallet->balance($user);
            $amount = (int) $data['amount_minor'];
            $delta = $kind === 'set' ? $amount - $before : ($kind === 'debit' ? -$amount : $amount);
            $id = (string) Str::uuid();
            $transaction =
                $delta === 0
                    ? null
                    : $wallet->{$delta > 0 ? 'credit' : 'debit'}(
                        $user,
                        abs($delta),
                        'link:'.$id,
                        'link.wallet_'.$kind,
                        ['server_id' => $server->id, 'token_id' => $grant->id],
                        $actor,
                    );
            $result = [
                'operation_id' => $id,
                'transaction_id' => $transaction?->id,
                'balance_before_minor' => $before,
                'balance_after_minor' => $wallet->balance($user),
                'currency' => $wallet->currency(),
            ];
            LinkAccountOperation::query()->create([
                'id' => $id,
                'token_id' => $grant->id,
                'user_id' => $user->id,
                'operation_key' => $key,
                'payload_hash' => $hash,
                'kind' => $kind,
                'result' => $result,
                'created_at' => now(),
            ]);
            Audit::record(
                'link.wallet_adjusted',
                [
                    'server' => $server->id,
                    'id' => $user->id,
                    'operation' => $id,
                    'kind' => $kind,
                    'amount_minor' => $amount,
                ],
                $actor,
            );

            return $result;
        });
    }

    /**
     * @throws JsonException
     */
    private function registrationPolicy(Server $server): array
    {
        app()->setLocale(app(LocalePolicy::class)->defaultLocale());
        $accountPolicy = app(AccountPolicy::class);
        $terms = $accountPolicy->terms();

        return [
            'registration_enabled' => $this->settings->get('registration.enabled', true) && app(AuthPolicy::class)->allowsLocal(),
            'email_required' => $accountPolicy->requiresRegistrationEmail(),
            'email_verification_required' => $accountPolicy->requiresVerifiedEmail(),
            'game' => $server->game,
            'locale' => app()->getLocale(),
            'terms' => $terms,
            'terms_version' => hash('sha256', json_encode([app()->getLocale(), $terms], JSON_THROW_ON_ERROR)),
            'captcha' => array_intersect_key(
                app(Captcha::class)->configuration('register') ?? [],
                array_flip(['id', 'site_key', 'script', 'field', 'action']),
            ),
        ];
    }

    private function publicProfile(User $user, GameIdentity $identity): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'game' => $identity->game,
            'identity' => [
                'uuid' => $identity->uuid,
                'username' => $identity->username,
                'verified' => (bool) $identity->verified_at,
            ],
        ];
    }

    private function invalidate(User $user): void
    {
        $user
            ->forceFill([
                'remember_token' => Str::random(60),
                'auth_session_version' => $user->auth_session_version + 1,
            ])
            ->save();
        ApiAccessToken::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}
