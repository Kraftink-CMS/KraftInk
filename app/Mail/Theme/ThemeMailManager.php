<?php

namespace App\Mail\Theme;

use Illuminate\Mail\MailManager;

class ThemeMailManager extends MailManager
{
    public function build($config): ThemeMailer
    {
        $mailer = new ThemeMailer(
            $config['name'] ?? 'ondemand',
            $this->app['view'],
            $this->createSymfonyTransport($config),
            $this->app['events'],
        );
        if ($this->app->bound('queue')) {
            $mailer->setQueue($this->app['queue']);
        }

        return $mailer;
    }
}
