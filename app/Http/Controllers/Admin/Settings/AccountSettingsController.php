<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Auth\AccountPolicy;
use App\Kraft\Auth\HashingPolicy;
use App\Kraft\Auth\Passkeys;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Games\GameSettings;
use App\Kraft\Html;
use App\Kraft\LocalePolicy;
use App\Kraft\OAuth\OAuthChanges;
use App\Kraft\Settings;
use App\Models\Accounts\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountSettingsController extends Controller
{
    public function index(
        Request $request,
        Settings $settings,
        AccountPolicy $policy,
        GameRegistry $games,
        GameSettings $configuration,
    ): View {
        $algorithms = HashingPolicy::algorithms();
        $hashDriver = $settings->get('auth.hash_driver', 'bcrypt');
        $selectedHashDriver = $request->old('hash_driver', $hashDriver);
        if (!is_string($selectedHashDriver) || !array_key_exists($selectedHashDriver, $algorithms)) {
            $selectedHashDriver = $hashDriver;
        }

        return view('admin.settings.accounts.index', [
            'values' => $settings->all(),
            'policy' => $policy,
            'algorithms' => $algorithms,
            'hashDriver' => $hashDriver,
            'selectedHashDriver' => $selectedHashDriver,
            'hashChanged' => $selectedHashDriver !== $hashDriver,
            'activeGame' => $games->activeId(),
            'gameAccess' => $configuration->forDisplay($games->active(), $request, siteAccess: true),
        ]);
    }

    public function store(
        Request $request,
        Settings $settings,
        GameSettings $configuration,
        AccountPolicy $accountPolicy,
        OAuthChanges $oauthChanges,
    ): RedirectResponse {
        $locales = array_keys(config('kraft.locales'));
        $policy = app(LocalePolicy::class);
        $editableLocales = $policy->allowsChoice() ? $locales : [$policy->defaultLocale()];
        $data = $request->validate([
            'allow_username' => 'required|boolean',
            'allow_avatar' => 'prohibited',
            'allow_deletion' => 'required|boolean',
            'hash_driver' => ['required', Rule::in(array_keys(HashingPolicy::algorithms()))],
            'terms_url' => 'nullable|url:http,https|max:2000',
            'terms' => 'nullable|array:'.implode(',', $locales),
            'terms.*' => 'nullable|string|max:30000',
            'registration_enabled' => 'sometimes|required|boolean',
            'email_required' => 'sometimes|required|boolean',
            'email_verification_required' => 'sometimes|required|boolean',
            'auth_mode' => ['sometimes', 'required', Rule::in(['local', 'external', 'mixed'])],
            'minimum' => 'sometimes|required|integer|between:12,128',
            'mixed_case' => 'sometimes|required|boolean',
            'symbols' => 'sometimes|required|boolean',
            'external_password_allowed' => 'sometimes|required|boolean',
            'external_password_required' => 'sometimes|required|boolean',
            'auth_form_presentation' => ['sometimes', 'required', Rule::in(['page', 'modal'])],
            'passkeys_enabled' => 'sometimes|required|boolean',
        ]);
        $passwordSettings = isset($data['minimum']) ? ['auth.password.min' => (int) $data['minimum']] : [];
        foreach ([
            'mixed_case' => 'auth.password.mixed_case',
            'symbols' => 'auth.password.symbols',
        ] as $field => $key) {
            if (isset($data[$field])) {
                $passwordSettings[$key] = (bool) $data[$field];
            }
        }
        $changesPasskeys = fn (): bool => array_key_exists('passkeys_enabled', $data) &&
            (bool) $data['passkeys_enabled'] !== (bool) $settings->get('auth.passkeys_enabled', true);
        $changesMode = fn (): bool => isset($data['auth_mode']) && $data['auth_mode'] !== $settings->get('auth.mode', 'mixed');
        $emailPolicy = isset($data['email_required'])
            ? ($data['email_required'] ? (!empty($data['email_verification_required']) ? 'verified' : 'required') : 'optional')
            : null;
        $externalPassword = isset($data['external_password_allowed'])
            ? ($data['external_password_allowed'] ? (!empty($data['external_password_required']) ? 'required' : 'optional') : 'disabled')
            : null;
        $actor = $request->user();
        $signInAgain = false;
        $configuration->save($request, function () use (
            $settings,
            $data,
            $request,
            $editableLocales,
            $changesPasskeys,
            $changesMode,
            $accountPolicy,
            $emailPolicy,
            $externalPassword,
            $passwordSettings,
            $oauthChanges,
            $actor,
            &$signInAgain,
        ): void {
            if ($emailPolicy === 'verified' && !$accountPolicy->requiresVerifiedEmail()) {
                $currentActor = User::query()->lockForUpdate()->findOrFail($actor->id);
                if (!$currentActor->email || !$currentActor->hasVerifiedEmail()) {
                    throw ValidationException::withMessages([
                        'email_verification_required' => __('auth_flow.admin.verify_email_first'),
                    ]);
                }
            }
            if ($changesPasskeys() && !$data['passkeys_enabled']) {
                app(Passkeys::class)->ensureCanDisable();
            }
            foreach (['username', 'deletion'] as $feature) {
                $settings->set('account.allow_'.$feature, $request->boolean('allow_'.$feature));
            }
            $settings->set('account.terms_url', $data['terms_url'] ?? null);
            $terms = $settings->get('account.terms', []);
            foreach ($editableLocales as $locale) {
                $terms[$locale] = Html::clean($data['terms'][$locale] ?? '');
            }
            $settings->set('account.terms', $terms);
            $settings->set('auth.hash_driver', $data['hash_driver']);
            foreach ([
                'registration_enabled' => 'registration.enabled',
                'passkeys_enabled' => 'auth.passkeys_enabled',
            ] as $field => $key) {
                if (array_key_exists($field, $data)) {
                    $settings->set($key, (bool) $data[$field]);
                }
            }
            if (isset($data['auth_form_presentation'])) {
                $settings->set('auth.form_presentation', $data['auth_form_presentation']);
            }
            if ($emailPolicy !== null) {
                $settings->set(AccountPolicy::EMAIL_POLICY_SETTING, $emailPolicy);
            }
            if ($externalPassword !== null) {
                $settings->set(AccountPolicy::EXTERNAL_PASSWORD_SETTING, $externalPassword);
            }
            foreach ($passwordSettings as $key => $value) {
                $settings->set($key, $value);
            }
            if ($changesMode()) {
                $plan = $oauthChanges->applyMode($data['auth_mode'], $actor);
                $signInAgain = in_array($actor->id, $plan['revoked'], true);
            }
            Audit::record('accounts.policy_updated', ['hash_driver' => $data['hash_driver']]);
        }, confirmSettings: function () use ($changesPasskeys, $changesMode, $data, $settings, $accountPolicy, $emailPolicy, $externalPassword, $passwordSettings): bool {
            $currentEmailPolicy = $accountPolicy->emailPolicy();

            return $changesPasskeys() || $changesMode() ||
                $data['hash_driver'] !== $settings->get('auth.hash_driver', 'bcrypt') ||
                ($externalPassword !== null && $externalPassword !== $accountPolicy->externalPasswordPolicy()) ||
                ($emailPolicy !== null && $emailPolicy !== $currentEmailPolicy &&
                    in_array('verified', [$emailPolicy, $currentEmailPolicy], true)) ||
                array_any(
                    array_keys($passwordSettings),
                    fn (string $key): bool => $passwordSettings[$key] !==
                        $settings->get($key, $key === 'auth.password.min' ? 12 : false),
                );
        });
        if ($signInAgain) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', __('admin.integrations.oauth.changed_sign_in'));
        }

        return back()->with('status', __('common.labels.settings_have_been_saved'));
    }
}
