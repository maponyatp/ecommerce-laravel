<?php

namespace App\Services;

use App\Models\StaffSecurityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class StaffSecurityService
{
    public function record(string $event, ?User $actor = null, ?Model $subject = null, array $details = [], string $outcome = 'success'): ?StaffSecurityLog
    {
        if (!Schema::hasTable('staff_security_logs')) { return null; }
        // Explicit metadata only. No request bodies, query strings, credentials, tokens or old/new values.
        $safe = [];
        foreach (['fields', 'actions', 'role_ids', 'template', 'provider', 'rule_id', 'mode', 'status_code', 'reason_code', 'release_key'] as $key) {
            if (!array_key_exists($key, $details)) { continue; }
            $value = $details[$key];
            $safe[$key] = is_array($value)
                ? array_slice(array_values(array_filter(array_map(fn ($v) => is_scalar($v) ? mb_substr((string) $v, 0, 100) : '', $value))), 0, 40)
                : (is_scalar($value) ? mb_substr((string) $value, 0, 150) : null);
        }
        $request = app()->bound('request') ? request() : null;
        $ip = $request?->ip();
        return StaffSecurityLog::create(['actor_id' => $actor?->id, 'event' => mb_substr($event, 0, 100),
            'outcome' => $outcome, 'subject_type' => $subject ? class_basename($subject) : null, 'subject_id' => $subject?->getKey(),
            'ip_address' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
            'route_name' => $request?->route()?->getName(), 'method' => $request?->method(), 'details' => $safe, 'created_at' => now()]);
    }

    public function bestEffort(string $event, ?User $actor = null, ?Model $subject = null, array $details = [], string $outcome = 'success'): void
    {
        try { $this->record($event, $actor, $subject, $details, $outcome); }
        catch (\Throwable $e) { Log::warning('Security audit persistence failed.', ['event' => $event, 'exception_type' => get_class($e)]); }
    }

    public function authEvent(object $event): void
    {
        $actor = ($event->user ?? null) instanceof User ? $event->user : null;
        $name = class_basename($event);
        if ($actor && !$actor->hasAnyRole(['admin', 'super_admin'])) { return; }
        if (!$actor && !in_array($name, ['Failed', 'Lockout'], true)) { return; }
        $this->bestEffort('auth.'.Strtolower($name), $actor, $actor, [], in_array($name, ['Failed', 'Lockout'], true) ? 'denied' : 'success');
    }

    public function modelChanged(Model $model, string $event): void
    {
        $actor = Auth::user();
        if (!$actor instanceof User || !$actor->hasAnyRole(['admin', 'super_admin'])) { return; }
        $fields = array_keys($event === 'updated' ? $model->getChanges() : $model->getAttributes());
        $this->bestEffort('model.'.$event, $actor, $model, ['fields' => array_values(array_diff($fields, ['updated_at', 'created_at']))]);
    }

    public static function requireSuperAdmin(User $actor): void
    {
        $current = $actor->fresh();
        abort_unless($current && !$current->staff_access_disabled_at && $current->hasRole('super_admin'), 403);
    }
}
