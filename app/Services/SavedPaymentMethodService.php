<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SavedPaymentMethodService
{
    public function save(int $userId, array $data, ?int $id = null): PaymentMethod
    {
        $data = array_intersect_key($data, array_flip(['name', 'details', 'is_default']));

        return DB::transaction(function () use ($userId, $data, $id) {
            // Serialize all reference mutations for this owner, including the first.
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $method = $id === null ? new PaymentMethod(['user_id' => $userId])
                : PaymentMethod::where('user_id', $userId)->findOrFail($id);
            $method->fill($data)->save();
            $this->normalizeDefaults($userId, ($data['is_default'] ?? false) ? $method->id : null);

            return $method->refresh();
        }, 3);
    }

    public function delete(int $userId, int $id): void
    {
        DB::transaction(function () use ($userId, $id) {
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            PaymentMethod::where('user_id', $userId)->findOrFail($id)->delete();
            $this->normalizeDefaults($userId);
        }, 3);
    }

    private function normalizeDefaults(int $userId, ?int $preferred = null): void
    {
        $keep = $preferred ?? PaymentMethod::where('user_id', $userId)->where('is_default', true)->orderBy('id')->value('id');
        if ($keep !== null) {
            PaymentMethod::where('user_id', $userId)->where('id', '!=', $keep)->where('is_default', true)->update(['is_default' => false]);
        }
    }
}
