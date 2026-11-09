<?php

namespace App\Kraft\Auth;

use App\Kraft\Auth\InGameSignIn\InGameSignInPolicy;
use App\Kraft\OAuth\OAuthChanges;
use App\Kraft\Settings;
use App\Models\Accounts\Passkey;
use App\Models\Accounts\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\SerializerInterface;
use TypeError;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\Exception\WebauthnException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

class Passkeys
{
    public const int LIMIT = 20;

    private const int LIFETIME = 180;

    private SerializerInterface $serializer;

    public static function host(?string $siteUrl = null): ?string
    {
        $url = parse_url($siteUrl ?? (string) config('app.url'));
        $host = strtolower($url['host'] ?? '');
        if (
            !$host ||
            !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) ||
            filter_var($host, FILTER_VALIDATE_IP) ||
            !(($url['scheme'] ?? null) === 'https' || (($url['scheme'] ?? null) === 'http' && $host === 'localhost'))
        ) {
            return null;
        }

        return $host;
    }

    public function available(Request $request): bool
    {
        return app(AuthPolicy::class)->allowsLocal() && $this->enabledHost() === strtolower($request->getHost());
    }

    public function enabled(): bool
    {
        return (bool) app(Settings::class)->get('auth.passkeys_enabled', true);
    }

    public function enabledHost(): ?string
    {
        return $this->enabled() ? self::host() : null;
    }

    public function ensureCanDisable(): void
    {
        $host = $this->enabledHost();
        if ($host === null || !app(AuthPolicy::class)->allowsLocal()) {
            return;
        }

        $affected = User::members()
            ->where(fn ($query) => $query->where('local_login_enabled', false)->orWhereNull('password')->orWhere('password', ''))
            ->where('import_locked', false)
            ->where('force_password_reset', false)
            ->whereNull('anonymized_at')
            ->withoutActiveBan()
            ->whereHas('passkeys', fn ($keys) => $keys->where('rp_id', $host))
            ->lazyById();
        foreach ($affected as $user) {
            if (!$this->hasAlternateSignIn($user)) {
                throw ValidationException::withMessages(['passkeys_enabled' => __('passkeys.disable_locked')]);
            }
        }
    }

    public function ensureAlternateAccessForSiteUrl(string $url): void
    {
        $current = $this->enabledHost();
        $next = self::host($url);
        if ($current === null || $current === $next || !app(AuthPolicy::class)->allowsLocal()) {
            return;
        }
        $affected = User::members()
            ->where('local_login_enabled', false)
            ->where('import_locked', false)
            ->whereNull('anonymized_at')
            ->withoutActiveBan()
            ->whereHas('passkeys', fn ($keys) => $keys->where('rp_id', $current))
            ->when($next !== null, fn ($query) => $query->whereDoesntHave('passkeys', fn ($keys) => $keys->where('rp_id', $next)))
            ->cursor();
        foreach ($affected as $user) {
            if (!$this->hasAlternateSignIn($user)) {
                throw ValidationException::withMessages(['site_url' => __('passkeys.domain_locked')]);
            }
        }
    }

    public function hasAlternateSignIn(User $user): bool
    {
        return app(OAuthChanges::class)->usable($user, includePasskeys: false) ||
            app(InGameSignInPolicy::class)->canSignIn($user);
    }

    public function begin(Request $request, ?User $user = null): array
    {
        $host = self::host();
        abort_unless($host !== null && $this->available($request), 403);
        if ($user !== null) {
            $keys = $user->passkeys()->where('rp_id', $host)->get();
            $records = $keys->map(fn (Passkey $key): CredentialRecord => $this->record($key));
            $options = PublicKeyCredentialCreationOptions::create(
                PublicKeyCredentialRpEntity::create(config('app.name'), $host),
                PublicKeyCredentialUserEntity::create(
                    $user->username,
                    $records->first()?->userHandle ?? random_bytes(32),
                    $user->username,
                ),
                random_bytes(32),
                [PublicKeyCredentialParameters::createPk(-7), PublicKeyCredentialParameters::createPk(-257)],
                AuthenticatorSelectionCriteria::create(userVerification: 'required', residentKey: 'required'),
                PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
                $records->map(fn (CredentialRecord $record) => $record->getPublicKeyCredentialDescriptor())->all(),
                120000,
            );
        } else {
            $options = PublicKeyCredentialRequestOptions::create(
                random_bytes(32),
                $host,
                userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                timeout: 120000,
            );
        }
        $optionsJson = $this->serializer()->serialize($options, 'json');
        $request->session()->put($this->sessionKey($user), [
            'origin' => $this->origin(),
            'options' => $optionsJson,
            'expires_at' => time() + self::LIFETIME,
            'user' => $user?->id,
            'version' => (int) $user?->auth_session_version,
        ]);

        return json_decode($optionsJson, true, flags: JSON_THROW_ON_ERROR);
    }

    public function options(Request $request, ?User $user = null): PublicKeyCredentialOptions
    {
        $state = $request->session()->pull($this->sessionKey($user));
        abort_unless($this->enabled(), 403);
        if (
            !$state ||
            !$this->available($request) ||
            $state['expires_at'] <= time() ||
            $state['origin'] !== $this->origin() ||
            $state['user'] !== $user?->id ||
            $state['version'] !== (int) $user?->auth_session_version
        ) {
            throw ValidationException::withMessages(['credentials' => __('passkeys.expired')]);
        }

        return $this->serializer()->deserialize(
            $state['options'],
            $user === null ? PublicKeyCredentialRequestOptions::class : PublicKeyCredentialCreationOptions::class,
            'json',
        );
    }

    public function response(string $json): PublicKeyCredential
    {
        try {
            $credential = $this->serializer()->deserialize($json, PublicKeyCredential::class, 'json');
        } catch (ExceptionInterface|WebauthnException|InvalidArgumentException|JsonException|RuntimeException|TypeError) {
            $this->invalid();
        }
        if ($credential->response->clientDataJSON->crossOrigin) {
            $this->invalid();
        }

        return $credential;
    }

    public function register(PublicKeyCredential $credential, PublicKeyCredentialCreationOptions $options): CredentialRecord
    {
        if (!$credential->response instanceof AuthenticatorAttestationResponse) {
            $this->invalid();
        }
        try {
            $validator = AuthenticatorAttestationResponseValidator::create($this->ceremony()->creationCeremony());
            $record = $validator->check($credential->response, $options, $options->rp->id);
        } catch (WebauthnException|InvalidArgumentException) {
            $this->invalid();
        }
        if (!hash_equals($record->publicKeyCredentialId, $credential->rawId)) {
            $this->invalid();
        }

        return $record;
    }

    public function verify(
        Passkey $passkey,
        PublicKeyCredential $credential,
        PublicKeyCredentialRequestOptions $options,
    ): CredentialRecord {
        if (!$credential->response instanceof AuthenticatorAssertionResponse) {
            $this->invalid();
        }
        try {
            $validator = AuthenticatorAssertionResponseValidator::create($this->ceremony()->requestCeremony());

            return $validator->check($this->record($passkey), $credential->response, $options, $options->rpId, null);
        } catch (WebauthnException|InvalidArgumentException) {
            $this->invalid();
        }
    }

    public function record(Passkey $passkey): CredentialRecord
    {
        return $this->serializer()->deserialize($passkey->credential, CredentialRecord::class, 'json');
    }

    public function serialize(CredentialRecord $record): string
    {
        return $this->serializer()->serialize($record, 'json');
    }

    public function invalid(): never
    {
        throw ValidationException::withMessages(['credentials' => __('passkeys.invalid')]);
    }

    private function sessionKey(?User $user): string
    {
        return $user === null ? 'auth.passkeys.login' : 'auth.passkeys.enroll';
    }

    private function origin(): string
    {
        $url = parse_url((string) config('app.url'));
        $scheme = $url['scheme'];
        $port = $url['port'] ?? null;

        return $scheme.'://'.self::host().($port && $port !== ($scheme === 'https' ? 443 : 80) ? ':'.$port : '');
    }

    private function ceremony(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins([$this->origin()]);

        return $factory;
    }

    private function serializer(): SerializerInterface
    {
        return $this->serializer ??= new WebauthnSerializerFactory(
            new AttestationStatementSupportManager([new NoneAttestationStatementSupport()]),
        )->create();
    }
}
