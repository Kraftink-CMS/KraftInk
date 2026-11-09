<?php

namespace App\Kraft\Auth;

use App\Models\Accounts\User;
use Illuminate\Notifications\Messages\MailMessage;

class AuthMails
{
    public function reset(User $user, string $token): MailMessage
    {
        $recovery = $user->oauth_recovery_required_at !== null;

        return $this->message(
            $user,
            $recovery ? 'recovery_title' : 'reset_title',
            $recovery ? 'recovery_intro' : 'reset_intro',
            $recovery ? 'create_password' : 'reset_action',
            route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]),
            __(
                'site.auth.mail.expires',
                ['count' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire')],
                $user->preferredLocale(),
            ),
        );
    }

    public function verify(User $user, string $url): MailMessage
    {
        return $this->message($user, 'verify_title', 'verify_intro', 'verify_action', $url, null);
    }

    private function message(
        User $user,
        string $title,
        string $intro,
        string $action,
        string $url,
        ?string $expires,
    ): MailMessage {
        $locale = $user->preferredLocale();
        $subject = __('site.auth.mail.'.$title, [], $locale);

        return new MailMessage()->subject($subject)->view([
            'html' => 'emails.account.notification',
            'text' => 'emails.account.notification-text',
        ], [
            'locale' => $locale,
            'subject' => $subject,
            'greeting' => __('site.auth.mail.greeting', ['name' => $user->username], $locale),
            'intro' => __('site.auth.mail.'.$intro, [], $locale),
            'action' => __('site.auth.mail.'.$action, [], $locale),
            'actionUrl' => $url,
            'expires' => $expires,
            'ignore' => __('site.auth.mail.'.($intro === 'recovery_intro' ? 'recovery_help' : 'ignore'), [], $locale),
        ]);
    }
}
