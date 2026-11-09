<?php

namespace App\Kraft\Auth;

use App\Kraft\Secrets;
use App\Kraft\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use App\Kraft\Auth\Contracts\CaptchaProvider;

class Captcha
{
    public const array PROVIDERS = [
        'turnstile' => [
            'name' => 'Cloudflare Turnstile',
            'url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            'script' => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
            'class' => 'cf-turnstile',
            'field' => 'cf-turnstile-response',
            'match_action' => true,
        ],
        'hcaptcha' => [
            'name' => 'hCaptcha',
            'url' => 'https://api.hcaptcha.com/siteverify',
            'script' => 'https://js.hcaptcha.com/1/api.js',
            'class' => 'h-captcha',
            'field' => 'h-captcha-response',
            'send_sitekey' => true,
        ],
        'recaptcha' => [
            'name' => 'Google reCAPTCHA v2',
            'url' => 'https://www.google.com/recaptcha/api/siteverify',
            'script' => 'https://www.google.com/recaptcha/api.js',
            'class' => 'g-recaptcha',
            'field' => 'g-recaptcha-response',
        ],
    ];

    public function providers(): array
    {
        return config('captcha.providers', self::PROVIDERS);
    }

    public function configuration(string $action): ?array
    {
        /** @var Settings $settings */
        $settings = app(Settings::class);
        $provider = $settings->get('security.captcha.provider', 'none');
        $providers = $this->providers();
        if (!isset($providers[$provider]) || !$settings->get('security.captcha.'.$action, false)) {
            return null;
        }

        return $providers[$provider] + [
            'id' => $provider,
            'site_key' => $settings->get('security.captcha.site_key', ''),
            'action' => $action,
        ];
    }

    public function verify(Request $request, string $action): void
    {
        $config = $this->configuration($action);
        if (!$config) {
            return;
        }
        $token = $request->input('captcha_response', $request->input($config['field']));
        if (!is_string($token) || trim($token) === '' || strlen($token) > 4096) {
            throw ValidationException::withMessages(['captcha' => __('admin.settings.security.captcha_required')]);
        }
        try {
            $secret = app(Secrets::class)->get('captcha.'.$config['id']);
            if ($secret === '' || $config['site_key'] === '') {
                throw new RuntimeException();
            }
            if (
                !Cache::add(
                    'security.captcha.'.hash('sha256', $config['id'].'|'.$token),
                    true,
                    now()->addMinutes(10),
                )
            ) {
                throw ValidationException::withMessages(['captcha' => __('admin.settings.security.captcha_invalid')]);
            }
            $driver = app()->make($config['driver'] ?? RemoteCaptchaProvider::class, ['configuration' => $config]);
            if (!($driver instanceof CaptchaProvider)) {
                throw new RuntimeException();
            }
            $valid = $driver->verify($request, $token, $secret, $config['site_key'], $action);
            if (!$valid) {
                throw ValidationException::withMessages(['captcha' => __('admin.settings.security.captcha_invalid')]);
            }
        } catch (ValidationException $error) {
            throw $error;
        } catch (Throwable) {
            throw ValidationException::withMessages(['captcha' => __('admin.settings.security.captcha_unavailable')]);
        }
    }
}
