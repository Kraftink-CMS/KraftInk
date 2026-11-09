<?php

namespace App\Kraft\System;

use App\Kraft\Secrets;
use App\Kraft\Settings;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Process\Process;
use RuntimeException;

class MailConfiguration
{
    public function __construct(private readonly Settings $settings, private readonly Secrets $secrets)
    {
    }

    public function configuration(): array
    {
        return [
            'transport' => $this->settings->get('mail.transport', 'smtp'),
            'host' => $this->settings->get('mail.host', ''),
            'port' => $this->settings->get('mail.port', 587),
            'security' => $this->settings->get('mail.security', 'starttls'),
            'username' => $this->settings->get('mail.username', ''),
        ];
    }

    public function requiresConfirmation(array $data): bool
    {
        $stored = $this->secrets->get('mail.password');
        $smtp = $data['transport'] === 'smtp';
        if ($smtp) {
            $next = !empty($data['clear_password']) ? '' : $data['mail_password'] ?? $stored;
            if ($next === '' && empty($data['clear_password'])) {
                $next = $stored;
            }
            if (!hash_equals($stored, $next)) {
                return true;
            }
        }
        $previous = $this->configuration();
        if ($data['transport'] !== $previous['transport']) {
            return true;
        }
        if ($smtp) {
            if (
                array_any(
                    ['host', 'port', 'security'],
                    fn ($key) => (string) ($data[$key] ?? '') !== (string) $previous[$key],
                )
            ) {
                return true;
            }
            if ($stored !== '' && (string) ($data['username'] ?? '') !== (string) $previous['username']) {
                return true;
            }
        }

        return false;
    }

    public function sendmailCommand(): ?string
    {
        $configured = config('mail.mailers.sendmail.path');
        if (
            !is_string($configured) ||
            !function_exists('proc_open') ||
            !preg_match('~^(?:"([^"\r\n]+)"|\'([^\'\r\n]+)\'|([^\s\'"\r\n]+)) -bs(?: -i)?$~D', $configured, $match)
        ) {
            return null;
        }
        $path = $match[1] ?: $match[2] ?? '' ?: $match[3] ?? '';
        $binary = realpath($path);
        if (!$binary || !is_file($binary) || !is_executable($binary)) {
            return null;
        }

        return new Process([$binary])->getCommandLine().' -bs -i';
    }

    public function apply(): void
    {
        $original = config('mail.kraftink_original') ?? [
            'default' => config('mail.default'),
            'from' => config('mail.from'),
        ];
        config(['mail.kraftink_original' => $original]);
        if (!$this->settings->get('mail.enabled', false)) {
            if (config('mail.default') === 'kraftink') {
                config(['mail.default' => $original['default'], 'mail.from' => $original['from']]);
                Mail::purge('kraftink');
            }

            return;
        }
        config([
            'mail.default' => 'kraftink',
            'mail.mailers.kraftink' => ['transport' => 'kraftink'],
            'mail.from.address' => $this->settings->get('mail.from_address'),
            'mail.from.name' => $this->settings->get('mail.from_name', config('app.name')),
        ]);
        Mail::purge('kraftink');
    }

    public function transport(): TransportInterface
    {
        if ($this->settings->get('mail.transport', 'smtp') === 'sendmail') {
            $command = $this->sendmailCommand();
            if (!$command) {
                throw new RuntimeException(__('admin.settings.system.sendmail_unavailable'));
            }

            return new SendmailTransport($command);
        }
        $security = $this->settings->get('mail.security', 'starttls');
        $username = $this->settings->get('mail.username', '');
        $password = $this->secrets->get('mail.password');
        $dsn = new Dsn(
            $security === 'smtps' ? 'smtps' : 'smtp',
            $this->settings->get('mail.host'),
            $username !== '' ? $username : null,
            $password !== '' ? $password : null,
            (int) $this->settings->get('mail.port', 587),
            ['auto_tls' => $security !== 'none', 'require_tls' => $security === 'starttls', 'timeout' => 15],
        );

        /** @var EsmtpTransport $transport */
        $transport = new EsmtpTransportFactory()->create($dsn);
        $transport->setUsername($username);
        $transport->setPassword($password);

        return $transport;
    }
}
