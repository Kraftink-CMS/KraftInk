<?php

namespace App\Kraft\System;

use App\Exceptions\ConfirmationRequired;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use App\Kraft\Auth\AuthPolicy;
use Illuminate\Http\Request;
use App\Kraft\Auth\TwoFactorVerifier;

class SensitiveAction
{
    public function confirm(Request $request, string $passwordField = 'password'): void
    {
        $requiresTwoFactor = (bool) $request->user()->two_factor_confirmed_at;
        $factorOnly = $requiresTwoFactor && ConfirmationRequired::applies($request);
        $previous = $request->attributes->get('kraft.confirmation.two_factor');
        $request->attributes->set('kraft.confirmation.two_factor', $requiresTwoFactor);
        $request->attributes->set('kraft.confirmation.confirmed', false);
        try {
            if (!$factorOnly) {
                app(AuthPolicy::class)->confirmCredentials($request, $passwordField);
            }
            if ($requiresTwoFactor) {
                $validator = Validator::make($request->all(), ['code' => 'required|string|max:32']);
                $validator->validate();
                app(TwoFactorVerifier::class)->verify($request->user()->id, $request->string('code')->toString());
            }
        } catch (ValidationException $exception) {
            if (ConfirmationRequired::applies($request)) {
                throw ConfirmationRequired::forRequest(
                    $request,
                    $passwordField,
                    ($factorOnly
                            ? $request->filled('code')
                            : $request->filled($passwordField) || $request->filled('code'))
                        ? $exception->errors()
                        : [],
                );
            }
            throw $exception;
        } finally {
            if ($previous === null) {
                $request->attributes->remove('kraft.confirmation.two_factor');
            } else {
                $request->attributes->set('kraft.confirmation.two_factor', $previous);
            }
        }
        $request->attributes->set('kraft.confirmation.confirmed', true);
    }
}
