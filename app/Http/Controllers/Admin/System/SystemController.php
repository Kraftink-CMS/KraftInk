<?php

namespace App\Http\Controllers\Admin\System;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Secrets;
use App\Kraft\Settings;
use App\Kraft\System\Diagnostics;
use App\Kraft\System\MailConfiguration;
use App\Kraft\System\SensitiveAction;
use App\Mail\SystemTestMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class SystemController extends Controller
{
    public function index(
        Settings $settings,
        Secrets $secrets,
        Diagnostics $diagnostics,
        MailConfiguration $configuration,
    ): View {
        $mail = [];
        foreach (
            ['enabled', 'transport', 'host', 'port', 'security', 'username', 'from_address', 'from_name'] as $key
        ) {
            $mail[$key] = $settings->get('mail.'.$key);
        }

        return view('admin.settings.system.index', [
            'mail' => $mail,
            'sendmailAvailable' => $configuration->sendmailCommand() !== null,
            'hasMailPassword' => $secrets->get('mail.password') !== '',
            'mailOriginal' => $configuration->configuration(),
            'diagnostics' => $diagnostics->collect(),
        ]);
    }

    public function saveMail(
        Request $request,
        Settings $settings,
        Secrets $secrets,
        MailConfiguration $mail,
        SensitiveAction $security,
    ): RedirectResponse {
        $request->merge(['transport' => $request->input('transport', $settings->get('mail.transport', 'smtp'))]);
        foreach ($mail->configuration() as $key => $value) {
            if (!$request->has($key)) {
                $request->merge([$key => $value]);
            }
        }
        $smtp = $request->input('transport') === 'smtp';
        $requiredSmtp = Rule::requiredIf($smtp && $request->boolean('enabled'));
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'transport' => ['required', Rule::in(['smtp', 'sendmail'])],
            'host' => [$requiredSmtp, 'nullable', 'string', 'max:255', 'regex:/^[a-zA-Z0-9.:-]+$/'],
            'port' => [$requiredSmtp, 'nullable', 'integer', 'between:1,65535'],
            'security' => [$requiredSmtp, 'nullable', Rule::in(['starttls', 'smtps', 'none'])],
            'username' => 'nullable|string|max:255',
            'mail_password' => 'nullable|string|max:1024',
            'clear_password' => 'sometimes|boolean',
            'from_address' => 'required_if:enabled,1|nullable|email|max:255',
            'from_name' => 'nullable|string|max:120',
            'sendmail_path' => 'prohibited',
            'command' => 'prohibited',
        ]);
        if ($data['transport'] === 'sendmail' && $request->boolean('enabled') && !$mail->sendmailCommand()) {
            throw ValidationException::withMessages(['transport' => __('admin.settings.system.sendmail_unavailable')]);
        }
        if ($mail->requiresConfirmation($data)) {
            $security->confirm($request);
        }
        DB::transaction(function () use ($settings, $secrets, $request, $data, $smtp): void {
            foreach (['enabled', 'transport', 'from_address', 'from_name'] as $key) {
                $settings->set('mail.'.$key, $key === 'enabled' ? $request->boolean($key) : $data[$key] ?? '');
            }
            if ($smtp) {
                foreach (['host', 'port', 'security', 'username'] as $key) {
                    $settings->set('mail.'.$key, $data[$key] ?? '');
                }
                if ($request->boolean('clear_password')) {
                    $secrets->set('mail.password', '');
                } elseif (($data['mail_password'] ?? '') !== '') {
                    $secrets->set('mail.password', $data['mail_password']);
                }
            }
        });
        $mail->apply();
        Audit::record('system.mail_settings_saved');

        return back()->with('status', __('admin.settings.system.settings_saved'));
    }

    public function testMail(Request $request, Settings $settings, MailConfiguration $configuration): RedirectResponse
    {
        if (!$settings->get('mail.enabled', false)) {
            throw new HttpException(422, __('admin.settings.system.mail_disabled'));
        }
        $data = $request->validate(['recipient' => 'required|email|max:255']);
        try {
            $configuration->apply();
            Mail::mailer('kraftink')->to($data['recipient'])->send(new SystemTestMail());
        } catch (Throwable $exception) {
            Log::warning('Mail test failed', ['exception' => $exception::class, 'code' => $exception->getCode()]);

            return back()->withErrors(['mail' => __('admin.settings.system.test_failed')]);
        }
        Audit::record('system.mail_test_sent');

        return back()->with('status', __('admin.settings.system.test_sent'));
    }
}
