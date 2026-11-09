<?php

namespace App\Kraft\Auth;

use App\Kraft\Html;
use App\Kraft\Auth\InGameSignIn\InGameSignInPolicy;
use App\Kraft\LocalePolicy;
use App\Kraft\Settings;
use App\Models\Accounts\User;

class AccountPolicy
{
    public const string EMAIL_POLICY_SETTING = 'registration.email_policy';

    public const array EMAIL_POLICIES = ['optional', 'required', 'verified'];

    public const string EXTERNAL_PASSWORD_SETTING = 'auth.external_password_policy';

    public function __construct(private Settings $settings)
    {
    }

    public function allows(string $feature): bool
    {
        return in_array($feature, ['username', 'deletion'], true) &&
            $this->settings->get('account.allow_'.$feature, true);
    }

    public function emailPolicy(): string
    {
        $policy = $this->settings->get(self::EMAIL_POLICY_SETTING, 'required');

        return in_array($policy, self::EMAIL_POLICIES, true) ? $policy : 'required';
    }

    public function requiresRegistrationEmail(): bool
    {
        return $this->emailPolicy() !== 'optional';
    }

    public function requiresVerifiedEmail(): bool
    {
        return $this->emailPolicy() === 'verified';
    }

    public function externalPasswordPolicy(): string
    {
        $policy = $this->settings->get(self::EXTERNAL_PASSWORD_SETTING, 'optional');

        return in_array($policy, ['disabled', 'optional', 'required'], true) ? $policy : 'optional';
    }

    public function allowsExternalPassword(): bool
    {
        return $this->externalPasswordPolicy() !== 'disabled';
    }

    public function requiresExternalPassword(): bool
    {
        return $this->externalPasswordPolicy() === 'required';
    }

    public function allowsSettingPassword(User $user): bool
    {
        return $this->allowsExternalPassword() || !$this->isExternalWithoutPassword($user);
    }

    public function allowsPasswordReset(User $user): bool
    {
        return !$this->isExternalWithoutPassword($user) ||
            ($user->hasVerifiedEmail() && ($this->allowsExternalPassword() || $user->oauth_recovery_required_at !== null));
    }

    public function terms(): array
    {
        $content = $this->settings->get('account.terms', []);
        $text = '';
        $policy = app(LocalePolicy::class);
        $locales = $policy->allowsChoice()
            ? array_unique([app()->getLocale(), 'en', $policy->defaultLocale()])
            : [$policy->defaultLocale()];
        foreach ($locales as $locale) {
            $candidate = Html::clean($content[$locale] ?? '');
            $plain = html_entity_decode(strip_tags($candidate, ['img', 'hr']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('/[^\s\p{Z}]/u', $plain)) {
                $text = $candidate;
                break;
            }
        }

        return [
            'url' => $this->settings->get('account.terms_url'),
            'text' => $text,
        ];
    }

    private function isExternalWithoutPassword(User $user): bool
    {
        return !$user->hasLocalPassword() && $user->oauthAccounts()->exists() &&
            !app(InGameSignInPolicy::class)->canSignIn($user);
    }
}
