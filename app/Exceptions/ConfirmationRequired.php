<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ConfirmationRequired extends HttpException
{
    public function __construct(private readonly array $confirmation, private readonly array $errors = [])
    {
        parent::__construct(428);
    }

    public static function applies(Request $request): bool
    {
        return $request->header('X-Kraft-Confirmation') === 'modal' &&
            $request->expectsJson() &&
            !$request->isMethodSafe() &&
            !$request->is('api/*') &&
            $request->hasSession();
    }

    public static function forRequest(
        Request $request,
        string $passwordField,
        array $errors = [],
        bool $reauthenticate = false,
    ): self {
        $twoFactor = (bool) $request->attributes->get('kraft.confirmation.two_factor', false);

        return new self([
            'password_field' => $passwordField,
            'password' => !$twoFactor && $request->user()->hasLocalPassword(),
            'two_factor' => $twoFactor,
            'reauthenticate' => $reauthenticate,
        ], $errors);
    }

    public function render(Request $request): JsonResponse
    {
        $data = ['confirmation' => $this->confirmation];
        if ($this->errors !== []) {
            $data['errors'] = $this->errors;
        }

        return response()->json(['success' => false, 'data' => $data], 428);
    }
}
