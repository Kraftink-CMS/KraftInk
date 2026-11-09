<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Kraft\Appearance;
use App\Kraft\Extensions\ExtensionManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Http\RedirectResponse;

class AppearanceController extends Controller
{
    public function __invoke(Request $request, Appearance $appearance, ExtensionManager $extensions): RedirectResponse
    {
        $policy = $appearance->policy($extensions->activeTheme());
        $data = $request->validate([
            'appearance' => ['required', 'string', Rule::in($policy['selectable'] ? $policy['modes'] : [])],
        ]);
        $cookie = $appearance->select($request, $data['appearance']);
        $previous = url()->previous();
        $origin = fn ($url) => [
            parse_url($url, PHP_URL_SCHEME),
            parse_url($url, PHP_URL_HOST),
            parse_url($url, PHP_URL_PORT),
        ];

        return redirect()
            ->to($origin($previous) === $origin($request->root()) ? $previous : url('/'))
            ->withCookie($cookie);
    }
}
