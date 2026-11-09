<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class NotificationMail extends Mailable
{
    public function __construct(public string $title, public string $text, public ?string $actionUrl = null)
    {
    }

    public function build(): static
    {
        return $this->subject($this->title)
            ->view('emails.community.notification')
            ->text('emails.community.notification-text');
    }
}
