<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Services\InvoiceDocumentService;
use App\Support\StoreBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Invoice $invoice) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your invoice from '.app(StoreBranding::class)->current()['name'],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.invoice',
            with: [
                'document' => app(InvoiceDocumentService::class)->document($this->invoice),
                'invoiceUrl' => URL::temporarySignedRoute('invoices.print', now()->addDays(30), ['invoice' => $this->invoice]),
            ],
        );
    }
}
