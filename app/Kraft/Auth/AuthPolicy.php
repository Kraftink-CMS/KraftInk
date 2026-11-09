<?php

namespace App\Kraft\Auth;

use App\Exceptions\ConfirmationRequired;
use App\Kraft\System\SensitiveAction;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Settings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class AuthPolicy
{
    public function __construct(private Settings $settings)
    {
    }

    public function allowsLocal(): bool
    {
        return $this->settings->get('auth.mode', 'mixed') !== 'external';
    }

    public function allowsExternal(): bool
    {
        return $this->settings->get('auth.mode', 'mixed') !== 'local' && $this->settings->get('oauth.enabled', true);
    }

    public function usesModalForms(): bool
    {
        return $this->settings->get('auth.form_presentation', 'page') === 'modal';
    }

    public function password(): Password
    {
        $rule = Password::min(max(12, (int) $this->settings->get('auth.password.min', 12)))
            ->max(255)
            ->letters()
            ->numbers()
            ->rules([
                function ($attribute, $value, $fail) {
                    if (app('hash')->getDefaultDriver() === 'bcrypt' && strlen($value) > 72) {
                        $fail(__('site.auth.password.too_long'));
                    }
                },
            ]);
        if ($this->settings->get('auth.password.mixed_case', false)) {
            $rule->mixedCase();
        }
        if ($this->settings->get('auth.password.symbols', false)) {
            $rule->symbols();
        }

        return $rule;
    }

    public function confirmCredentials(Request $request, string $passwordField = 'password'): void
    {
        if (ConfirmationRequired::applies($request) && $request->user()->two_factor_confirmed_at) {
            app(SensitiveAction::class)->confirm($request, $passwordField);

            return;
        }
        $request->attributes->set('kraft.confirmation.confirmed', false);
        if ($request->user()->hasLocalPassword()) {
            $validator = Validator::make($request->all(), [$passwordField => 'required|current_password']);
            if ($validator->fails()) {
                if (ConfirmationRequired::applies($request)) {
                    throw ConfirmationRequired::forRequest(
                        $request,
                        $passwordField,
                        $request->filled($passwordField) ? $validator->errors()->messages() : [],
                    );
                }
                throw new ValidationException($validator);
            }
        } else {
            if ((int) $request->session()->get('auth.confirmed_at', 0) <= time() - 600) {
                if (ConfirmationRequired::applies($request)) {
                    throw ConfirmationRequired::forRequest($request, $passwordField, reauthenticate: true);
                }
                throw new HttpException(403, __('site.auth.security.reauthenticate'));
            }
        }
        if (!$request->attributes->get('kraft.confirmation.two_factor', false)) {
            $request->attributes->set('kraft.confirmation.confirmed', true);
        }
    }
}
