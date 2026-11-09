<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SystemTestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: __('admin.settings.system.test_subject'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.system.test', text: 'emails.system.test-text');
    }
}
