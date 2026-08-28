<?php

namespace App\Services;

use App\Models\DeliverySlot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DeliverySlotManagementService
{
    public function save(?DeliverySlot $slot, array $data, User $actor): DeliverySlot
    {
        Gate::forUser($actor)->authorize($slot ? 'update' : 'create', $slot ?? DeliverySlot::class);
        $data = Validator::make($data, [
            'shipping_method_id' => 'required|integer|exists:shipping_methods,id',
            'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at',
            'booking_closes_at' => 'required|date|before_or_equal:starts_at',
            'capacity' => 'required|integer|min:1|max:10000', 'is_active' => 'required|boolean',
            'version' => 'required|integer|min:0',
        ])->validate();
        $start = Carbon::parse($data['starts_at']);
        $end = Carbon::parse($data['ends_at']);
        if ($start->copy()->timezone(config('commerce.delivery_timezone'))->toDateString()
            !== $end->copy()->timezone(config('commerce.delivery_timezone'))->toDateString()) {
            throw ValidationException::withMessages(['ends_at' => 'A delivery window must start and end on the same South African date.']);
        }

        return DB::transaction(function () use ($slot, $data, $start) {
            if (! $slot) {
                if (! $start->isFuture()) {
                    throw ValidationException::withMessages(['starts_at' => 'Create a future delivery window.']);
                }

                return DeliverySlot::create([...$data, 'version' => 0]);
            }
            $slot = DeliverySlot::lockForUpdate()->findOrFail($slot->id);
            if ($slot->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['capacity' => 'Another staff member changed this window. Reload before saving.']);
            }
            $slot->fill($data);
            if ($slot->bookings()->lockForUpdate()->get()->isNotEmpty()
                && $slot->isDirty(['shipping_method_id', 'starts_at', 'ends_at', 'booking_closes_at'])) {
                throw ValidationException::withMessages(['starts_at' => 'Booked windows cannot be moved or reassigned. Unpublish this window and create another.']);
            }
            $occupied = $slot->bookings()->occupying()->lockForUpdate()->get()->count();
            if ($slot->capacity < $occupied) {
                throw ValidationException::withMessages(['capacity' => "Capacity cannot be below the {$occupied} confirmed or held deliveries."]);
            }
            if ($slot->isDirty('starts_at') && ! $start->isFuture()) {
                throw ValidationException::withMessages(['starts_at' => 'Choose a future delivery window.']);
            }
            $slot->version++;
            $slot->save();

            return $slot;
        }, 3);
    }
}
