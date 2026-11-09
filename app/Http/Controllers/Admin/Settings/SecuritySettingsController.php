<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Auth\Captcha;
use App\Kraft\Secrets;
use App\Kraft\Settings;
use App\Kraft\System\SensitiveAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SecuritySettingsController extends Controller
{
    public function index(Settings $settings): View
    {
        return view('admin.settings.security.index', [
            'settings' => $settings->all(),
            'providers' => app(Captcha::class)->providers(),
        ]);
    }

    public function store(Request $request, Settings $settings, Secrets $secrets): RedirectResponse
    {
        app(SensitiveAction::class)->confirm($request);
        $data = $request->validate([
            'admin_two_factor' => 'required|boolean',
            'provider' => ['required', Rule::in(array_merge(['none'], array_keys(app(Captcha::class)->providers())))],
            'site_key' => 'exclude_if:provider,none|nullable|string|max:200',
            'captcha_secret' => 'exclude_if:provider,none|nullable|string|max:1000',
            'captcha_login' => 'exclude_if:provider,none|required|boolean',
            'captcha_register' => 'exclude_if:provider,none|required|boolean',
        ]);
        if ($request->boolean('admin_two_factor') && !$request->user()->two_factor_confirmed_at) {
            throw ValidationException::withMessages([
                'admin_two_factor' => __('admin.settings.security.enable_yours_first'),
            ]);
        }
        if (
            $data['provider'] !== 'none' &&
            ($request->boolean('captcha_login') || $request->boolean('captcha_register'))
        ) {
            if (
                empty($data['site_key']) ||
                (empty($data['captcha_secret']) && $secrets->get('captcha.'.$data['provider']) === '')
            ) {
                throw ValidationException::withMessages(['site_key' => __('admin.settings.security.keys_required')]);
            }
        }
        if (!empty($data['captcha_secret']) && $data['provider'] !== 'none') {
            $secrets->set('captcha.'.$data['provider'], $data['captcha_secret']);
        }
        foreach (
            [
                'admin_two_factor' => 'security.admin_two_factor',
                'provider' => 'security.captcha.provider',
                'site_key' => 'security.captcha.site_key',
                'captcha_login' => 'security.captcha.login',
                'captcha_register' => 'security.captcha.register',
            ] as $field => $key
        ) {
            if (
                $data['provider'] === 'none' &&
                in_array($field, ['site_key', 'captcha_login', 'captcha_register'], true)
            ) {
                continue;
            }
            $settings->set(
                $key,
                in_array($field, ['admin_two_factor', 'captcha_login', 'captcha_register'], true)
                    ? $request->boolean($field)
                    : $data[$field],
            );
        }
        Audit::record('security.settings_saved', [
            'provider' => $data['provider'],
            'admin_two_factor' => $request->boolean('admin_two_factor'),
        ]);

        return back()->with('status', __('admin.settings.security.saved'));
    }
}
