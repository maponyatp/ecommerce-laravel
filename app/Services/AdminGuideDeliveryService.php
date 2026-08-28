<?php

namespace App\Services;

use App\Mail\AdminSystemGuideMail;
use App\Models\User;
use App\Support\AdminFeatureGuide;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class AdminGuideDeliveryService
{
    public function recipients(): Collection
    {
        return User::whereHas('roles', fn ($q) => $q->where('guard_name', 'web')->whereIn('name', ['admin', 'super_admin']))
            ->when(Schema::hasColumn('users', 'staff_access_disabled_at'), fn ($q) => $q->whereNull('staff_access_disabled_at'))
            ->orderBy('id')->get()->unique(fn ($user) => strtolower(trim($user->email)))->values();
    }

    public function deliver(User $recipient, string $kind, string $campaign): array
    {
        if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $campaign) || ! in_array($kind, ['test', 'features'], true)) {
            throw new \InvalidArgumentException('Invalid campaign or email template.');
        }
        $recipient = $this->recipients()->firstWhere('id', $recipient->id);
        if (! $recipient || ! filter_var(trim($recipient->email), FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Recipient is not an eligible administrator with a valid email address.');
        }
        $mailer = config('mail.default');
        if (! app()->environment('testing') && ($mailer !== 'smtp' || config('mail.mailers.smtp.transport') !== 'smtp')) {
            throw new \RuntimeException('A real SMTP mailer is required; log, array and failover transports cannot confirm sending.');
        }
        $email = strtolower(trim($recipient->email));
        $key = ['campaign' => $campaign, 'kind' => $kind, 'recipient_hash' => hash('sha256', $email)];
        $existing = DB::table('admin_email_deliveries')->where($key)->first();
        if ($existing) {
            return ['status' => $existing->status, 'skipped' => true];
        }
        if (! app()->environment('testing')) {
            $transport = Mail::mailer($mailer)->getSymfonyTransport();
            if (! $transport instanceof EsmtpTransport) {
                throw new \RuntimeException('An authenticated TLS-capable SMTP transport is required.');
            }
            $transport->setRequireTls(true);
        }
        $mail = new AdminSystemGuideMail($recipient->name, $kind, $kind === 'features' ? app(AdminFeatureGuide::class)->content() : []);
        // Fail before claiming a send if the template cannot render.
        $mail->render();
        $claimed = DB::table('admin_email_deliveries')->insertOrIgnore($key + [
            'user_id' => $recipient->id, 'recipient_email' => Crypt::encryptString($email),
            'status' => 'sending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! $claimed) {
            $existing = DB::table('admin_email_deliveries')->where($key)->first();
            if (! $existing) {
                throw new \RuntimeException('Could not reserve the admin email delivery.');
            }

            return ['status' => $existing->status, 'skipped' => true];
        }
        try {
            $sent = Mail::mailer($mailer)->to($email)->send($mail);
            if (! $sent) {
                throw new \RuntimeException('Mail send was cancelled or not acknowledged.');
            }
            DB::table('admin_email_deliveries')->where($key)->update([
                'status' => 'accepted', 'accepted_at' => now(), 'updated_at' => now(),
                'message_id' => substr($sent->getMessageId(), 0, 255),
            ]);

            return ['status' => 'accepted', 'skipped' => false];
        } catch (\Throwable $e) {
            // A timeout may happen after SMTP accepted the message. Do not retry
            // automatically or persist exception messages that may contain secrets.
            DB::table('admin_email_deliveries')->where($key)->update([
                'status' => 'uncertain', 'error_class' => substr($e::class, 0, 255), 'updated_at' => now(),
            ]);

            return ['status' => 'uncertain', 'skipped' => false];
        }
    }
}
