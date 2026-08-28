<?php

namespace App\Console\Commands;

use App\Services\AdminGuideDeliveryService;
use Illuminate\Console\Command;

class EmailAdminSystemGuide extends Command
{
    protected $signature = 'commerce:email-admin-guide {--kind=features : features or test} {--campaign= : Stable release identifier for duplicate protection} {--send : Send individually to all active administrators; otherwise preview only}';

    protected $description = 'Preview or send the branded store feature guide/test to existing active administrators';

    public function handle(AdminGuideDeliveryService $service): int
    {
        $kind = (string) $this->option('kind');
        $campaign = (string) $this->option('campaign');
        if (! in_array($kind, ['test', 'features'], true) || ! preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $campaign)) {
            $this->error('Provide --kind=features or test and an explicit lowercase campaign key (up to 80 letters, numbers, hyphens or underscores).');

            return self::FAILURE;
        }
        $recipients = $service->recipients();
        if ($recipients->isEmpty()) {
            $this->error('No active administrator recipients found. No email sent.');

            return self::FAILURE;
        }
        $this->table(['Account', 'Recipient', 'Template'], $recipients->map(fn ($user) => [$user->id, $user->email, $kind])->all());
        if ($recipients->contains(fn ($user) => ! filter_var(trim($user->email), FILTER_VALIDATE_EMAIL))) {
            $this->error('An administrator has an invalid email. Correct it before sending; no email sent.');

            return self::FAILURE;
        }
        if (! $this->option('send')) {
            $this->info('Preview only. No mail or delivery records created. Add --send to dispatch.');

            return self::SUCCESS;
        }
        $failed = false;
        foreach ($recipients as $user) {
            try {
                $result = $service->deliver($user, $kind, $campaign);
                $this->line($user->email.': '.($result['skipped'] ? 'already recorded / ' : '').$result['status']);
                $failed = $failed || $result['status'] !== 'accepted';
            } catch (\Throwable $e) {
                $this->error($user->email.': could not complete delivery ('.$e::class.'). Review the private ledger/configuration; do not blindly retry.');
                $failed = true;
            }
        }
        $this->info('Accepted means the mail transport acknowledged the message, not confirmed inbox delivery. Uncertain or interrupted sends are never automatically repeated for this campaign.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
