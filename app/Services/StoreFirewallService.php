<?php

namespace App\Services;

use App\Models\FirewallRule;
use App\Models\SecuritySetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StoreFirewallService
{
    public function mode(): string { return SecuritySetting::find(1)?->firewall_mode ?? 'monitor'; }

    public function configure(string $mode, int $version, User $actor): void
    {
        StaffSecurityService::requireSuperAdmin($actor);
        Validator::make(['mode' => $mode], ['mode' => 'required|in:disabled,monitor,enforce'])->validate();
        DB::transaction(function () use ($mode, $version, $actor) {
            SecuritySetting::firstOrCreate(['id' => 1]);
            $settings = SecuritySetting::lockForUpdate()->findOrFail(1);
            if ($settings->version !== $version) { $this->invalid('Security settings changed. Reload before saving.'); }
            $settings->update(['firewall_mode' => $mode, 'version' => $version + 1]);
            app(StaffSecurityService::class)->record('firewall.mode_changed', $actor, $settings, ['mode' => $mode]);
        }, 3);
    }

    public function block(array $input, User $actor, string $currentIp): FirewallRule
    {
        StaffSecurityService::requireSuperAdmin($actor);
        $data = Validator::make($input, ['ip_address' => 'required|ip', 'reason' => 'required|string|min:5|max:255',
            'duration_hours' => 'required|integer|min:1|max:168'])->validate();
        $ip = inet_ntop(inet_pton($data['ip_address']));
        if ($ip === inet_ntop(inet_pton($currentIp)) || in_array($ip, ['127.0.0.1', '::1'], true)) {
            $this->invalid('You cannot block your current address or loopback. Use a different verified target.');
        }
        return DB::transaction(function () use ($ip, $data, $actor) {
            $rule = FirewallRule::firstOrCreate(['ip_address' => $ip], ['reason' => $data['reason'], 'created_by' => $actor->id]);
            $rule = FirewallRule::lockForUpdate()->findOrFail($rule->id);
            if ($rule->revoked_at === null && $rule->expires_at?->isFuture()) { $this->invalid('This address is already blocked. Review or revoke its existing rule.'); }
            $rule->update(['reason' => trim($data['reason']), 'expires_at' => now()->addHours((int) $data['duration_hours']),
                'revoked_at' => null, 'created_by' => $actor->id, 'version' => $rule->version + 1]);
            app(StaffSecurityService::class)->record('firewall.block_added', $actor, $rule, ['rule_id' => $rule->id]);
            return $rule;
        }, 3);
    }

    public function revoke(FirewallRule $rule, int $version, User $actor): void
    {
        StaffSecurityService::requireSuperAdmin($actor);
        DB::transaction(function () use ($rule, $version, $actor) {
            $rule = FirewallRule::lockForUpdate()->findOrFail($rule->id);
            if ($rule->version !== $version || $rule->revoked_at) { $this->invalid('The rule changed. Reload before revoking.'); }
            $rule->update(['revoked_at' => now(), 'version' => $rule->version + 1]);
            app(StaffSecurityService::class)->record('firewall.block_revoked', $actor, $rule, ['rule_id' => $rule->id]);
        }, 3);
    }

    private function invalid(string $message): never { throw ValidationException::withMessages(['settings' => $message]); }
}
