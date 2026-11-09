<?php

namespace App\Kraft\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthForms
{
    public static function render(
        Request $request,
        string $view,
        string $fragment,
        string $title,
        array $data = [],
    ): Response {
        $modal = $request->header('X-Kraft-Auth-Modal') === 'true' && app(AuthPolicy::class)->usesModalForms();

        return response()->view(
            $modal ? 'default.user.auth.partials.dialog' : $view,
            $data + ['authTitle' => $title, 'authFragment' => $fragment],
        )->withHeaders(['Vary' => 'X-Kraft-Auth-Modal', 'Cache-Control' => 'private, no-store']);
    }
}
