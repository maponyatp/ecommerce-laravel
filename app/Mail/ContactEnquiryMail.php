<?php

namespace App\Mail;

use App\Support\StoreBranding;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ContactEnquiryMail extends Mailable
{
    public function __construct(public array $enquiry) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->enquiry['email'])],
            subject: 'New enquiry from '.app(StoreBranding::class)->current()['name'],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.contact-enquiry');
    }
}
