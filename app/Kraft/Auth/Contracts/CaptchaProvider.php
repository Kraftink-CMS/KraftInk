<?php

namespace App\Kraft\Auth\Contracts;

use Illuminate\Http\Request;

interface CaptchaProvider
{
    public function verify(Request $request, string $token, string $secret, string $siteKey, string $action): bool;
}
