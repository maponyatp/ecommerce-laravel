<?php

namespace App\Listeners;

use App\Support\StoreBranding;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

class BrandOutgoingMail
{
    public function handle(MessageSending $event): void
    {
        $brand = app(StoreBranding::class)->current();
        $from = $event->message->getFrom();
        // Preserve the authenticated SMTP address and any explicitly different sender.
        if (count($from) === 1 && strcasecmp($from[0]->getAddress(), config('mail.from.address')) === 0) {
            $event->message->from(new Address($from[0]->getAddress(), preg_replace('/[\r\n]+/', ' ', $brand['name'])));
        }
        if (! $event->message->getReplyTo() && $brand['email']) {
            $event->message->replyTo(new Address($brand['email']));
        }
    }
}
