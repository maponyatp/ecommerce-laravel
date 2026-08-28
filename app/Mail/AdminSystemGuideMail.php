<?php

namespace App\Mail;

use App\Support\StoreBranding;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AdminSystemGuideMail extends Mailable
{
    public function __construct(public readonly string $recipientName, public readonly string $kind, public readonly array $guide)
    {
        if (! in_array($kind, ['test', 'features'], true)) {
            throw new \InvalidArgumentException('Unsupported admin email template.');
        }
    }

    public function envelope(): Envelope
    {
        $name = preg_replace('/[\r\n]+/', ' ', app(StoreBranding::class)->current()['name']);

        return new Envelope(subject: $name.($this->kind === 'test' ? ' | Branded email test' : ' | Store feature guide & testing checklist'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.admin-system-guide', with: [
            'storeUrl' => rtrim(config('app.url'), '/'),
        ]);
    }
}
