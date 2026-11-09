<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SessionVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (
            $user &&
            ($user->isServiceAccount() ||
                $user->anonymized_at ||
                (int) $request->session()->get('auth.version', 0) !== (int) $user->auth_session_version)
        ) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/login')->withErrors(['session' => __('site.account.privacy.session_revoked')]);
        }

        return $next($request);
    }
}
