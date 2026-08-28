<?php

namespace App\Services;

use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CustomerProfileService
{
    public function identityKey(Order $anchor): string
    {
        return hash('sha256', json_encode($this->identityParts($anchor), JSON_THROW_ON_ERROR));
    }

    private function identityParts(Order $anchor): array
    {
        $kind = app(CustomerDirectoryService::class)->kind($anchor);
        $identity = match ($kind) {
            'account' => (string) $anchor->user_id,
            'legacy' => (string) $anchor->customer_id,
            'guest' => $anchor->customer_email,
            default => (string) $anchor->id,
        };

        return ['v1', $anchor->team_id === null ? null : (string) $anchor->team_id, $kind, $identity];
    }

    public function directoryFields(Order $anchor): array
    {
        [, $team, $kind, $identity] = $this->identityParts($anchor);

        return ['directory_team_key' => $team ?? 'none', 'directory_kind' => $kind, 'directory_identity' => $identity];
    }

    public function find(Order $anchor): ?CustomerProfile
    {
        return CustomerProfile::where('identity_key', $this->identityKey($anchor))->first();
    }

    public function values(?CustomerProfile $profile): array
    {
        return ['preferred_name' => $profile?->preferred_name, 'labels' => $profile?->labels ?? [], 'staff_notes' => $profile?->staff_notes];
    }

    public function update(Order $anchor, array $input, User $actor): ?CustomerProfile
    {
        $this->authorize($anchor, $actor);
        $data = Validator::make($input, [
            'identity_key' => 'required|string|size:64', 'version' => 'required|integer|min:0',
            'preferred_name' => 'nullable|string|max:120', 'staff_notes' => 'nullable|string|max:4000',
            'labels' => 'present|array|max:10', 'labels.*' => ['required', 'string', 'max:30', 'regex:/^[\pL\pN][\pL\pN _-]*$/u'],
        ])->validate();
        $values = [
            'preferred_name' => filled($data['preferred_name'] ?? null) ? trim($data['preferred_name']) : null,
            'labels' => array_values(array_unique(array_map(fn ($label) => mb_strtolower(trim($label)), $data['labels']))),
            'staff_notes' => filled($data['staff_notes'] ?? null) ? trim($data['staff_notes']) : null,
        ];
        sort($values['labels'], SORT_STRING);

        return DB::transaction(function () use ($anchor, $data, $values, $actor) {
            $anchor = Order::findOrFail($anchor->id);
            $this->assertIdentity($anchor, $data['identity_key']);
            // Every entry point to the same contact locks the same oldest order before creating a profile.
            $canonicalId = app(CustomerDirectoryService::class)->ordersFor($anchor)->min('id');
            Order::lockForUpdate()->findOrFail($canonicalId);
            $anchor = Order::lockForUpdate()->findOrFail($anchor->id);
            $this->assertIdentity($anchor, $data['identity_key']);
            $this->authorize($anchor, $actor);
            $profile = CustomerProfile::where('identity_key', $data['identity_key'])->lockForUpdate()->first();
            if (($profile?->version ?? 0) !== (int) $data['version']) {
                throw ValidationException::withMessages(['staff_notes' => 'Another staff member changed this profile. Close this editor and reopen it before saving.']);
            }
            $before = $this->values($profile);
            if ($before === $values) {
                return $profile;
            }
            $profile ??= new CustomerProfile(['identity_key' => $data['identity_key'], 'version' => 0]);
            $profile->fill($this->directoryFields($anchor));
            $profile->fill($values);
            $profile->version++;
            $profile->save();
            $profile->changes()->create(['actor_id' => $actor->id, 'version' => $profile->version,
                'before_values' => $before, 'after_values' => $values]);

            return $profile->fresh();
        }, 3);
    }

    private function assertIdentity(Order $anchor, string $expected): void
    {
        if (! hash_equals($this->identityKey($anchor), $expected)) {
            throw ValidationException::withMessages(['staff_notes' => 'The order contact link has changed. Reload the customer history before editing.']);
        }
    }

    private function authorize(Order $anchor, User $actor): void
    {
        abort_unless($actor->hasAnyRole(['admin', 'super_admin']), 403);
        Gate::forUser($actor)->authorize('viewAny', Order::class);
        Gate::forUser($actor)->authorize('view', $anchor);
        Gate::forUser($actor)->authorize('update', $anchor);
    }
}
