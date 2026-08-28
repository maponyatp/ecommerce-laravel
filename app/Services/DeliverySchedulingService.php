<?php

namespace App\Services;

use App\Models\DeliveryBooking;
use App\Models\DeliverySlot;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\ShippingMethod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DeliverySchedulingService
{
    public function available(DeliverySlot $slot, ?int $exceptOrder = null, bool $lock = false): int
    {
        $bookings = $slot->bookings()->occupying();
        if ($exceptOrder) {
            $bookings->where('order_id', '!=', $exceptOrder);
        }
        // Current read after acquiring the slot lock, including under MySQL repeatable-read.
        $occupied = $lock ? $bookings->lockForUpdate()->get()->count() : $bookings->count();

        return max(0, $slot->capacity - $occupied);
    }

    public function choices(int $methodId)
    {
        return DeliverySlot::where('shipping_method_id', $methodId)->where('is_active', true)
            ->where('booking_closes_at', '>', now())->where('starts_at', '>', now())
            ->orderBy('starts_at')->get()->filter(fn ($slot) => $this->available($slot) > 0);
    }

    public function quote(?ShippingMethod $method, ?int $slotId): ?array
    {
        if (! $method?->requires_delivery_slot) {
            if ($slotId) {
                $this->invalid('Choose a delivery window only for a scheduled delivery method.');
            }

            return null;
        }
        $slot = DeliverySlot::find($slotId);
        $this->validateSlot($method, $slot);

        return $this->snapshot($slot);
    }

    /** Called after stock reservation, in the same order-creation transaction. */
    public function reserve(Order $order, ?int $slotId, ?array $quoted): void
    {
        $this->requireTransaction();
        $method = $order->shipping_method_id ? ShippingMethod::lockForUpdate()->findOrFail($order->shipping_method_id) : null;
        if (! $method?->requires_delivery_slot) {
            if ($slotId || $quoted) {
                $this->invalid('Delivery options changed. Review checkout again.');
            }

            return;
        }
        $slot = DeliverySlot::lockForUpdate()->find($slotId);
        $this->validateSlot($method, $slot, true);
        if ($this->snapshot($slot) !== $quoted) {
            $this->invalid('The delivery window changed. Review checkout again.');
        }
        DeliveryBooking::create([
            'order_id' => $order->id, 'delivery_slot_id' => $slot->id, 'status' => 'held',
            'expires_at' => $order->stock_reserved_until->min($slot->booking_closes_at),
        ]);
        $order->update(['delivery_slot_id' => $slot->id, 'delivery_scheduled_at' => $slot->starts_at,
            'delivery_window_end' => $slot->ends_at]);
    }

    /** Order and stock are already locked. Never oversell a window after a late payment. */
    public function confirm(Order $order): bool
    {
        $this->requireTransaction();
        if (! $order->delivery_slot_id) {
            return true; // Legacy orders and non-scheduled methods remain unchanged.
        }
        $slot = DeliverySlot::lockForUpdate()->findOrFail($order->delivery_slot_id);
        $booking = DeliveryBooking::where('order_id', $order->id)->lockForUpdate()->first();
        if ($booking?->status === 'confirmed') {
            return true;
        }
        $held = $booking?->status === 'held' && $booking->expires_at?->isFuture();
        // Unpublishing stops NEW bookings, but honours existing, unexpired holds.
        $bookable = $slot->is_active && $slot->booking_closes_at->isFuture() && $slot->starts_at->isFuture();
        if (! $booking || (! $held && ! $bookable) || $this->available($slot, $order->id, true) < 1) {
            $booking?->update(['status' => 'released']);

            return false;
        }
        $booking->update(['status' => 'confirmed', 'expires_at' => null]);

        return true;
    }

    public function release(Order $order): void
    {
        $this->requireTransaction();
        if ($order->delivery_slot_id) {
            DeliverySlot::whereKey($order->delivery_slot_id)->lockForUpdate()->first();
            DeliveryBooking::where('order_id', $order->id)->where('status', 'held')->update(['status' => 'released']);
        }
    }

    public function resolvePaidReview(Order $order, int $slotId, string $note, User $actor): Order
    {
        abort_unless($actor->hasAnyRole(['admin', 'super_admin']), 403);
        Gate::forUser($actor)->authorize('update', $order);
        Validator::make(['note' => trim($note)], ['note' => 'required|string|max:2000'])->validate();

        return DB::transaction(function () use ($order, $slotId, $note, $actor) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            if ($order->hasPendingRefund()) {
                $this->invalid('Resolve the pending refund before rebooking delivery.');
            }
            if ($order->status !== 'payment_received_delivery_review' || $order->payment_status !== 'paid'
                || ! $order->inventory_committed_at || $order->shipping_status !== 'unfulfilled') {
                $this->invalid('Only paid, unfulfilled orders awaiting delivery review can be rebooked here.');
            }
            $method = ShippingMethod::lockForUpdate()->findOrFail($order->shipping_method_id);
            $slot = DeliverySlot::lockForUpdate()->find($slotId);
            $this->validateSlot($method, $slot, true);
            $booking = DeliveryBooking::where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            if ($booking->status !== 'released') {
                $this->invalid('The previous delivery hold must be released before rebooking.');
            }
            $previousWindow = $order->delivery_window_label;
            $booking->update(['delivery_slot_id' => $slot->id, 'status' => 'confirmed', 'expires_at' => null]);
            $order->update(['delivery_slot_id' => $slot->id, 'delivery_scheduled_at' => $slot->starts_at,
                'delivery_window_end' => $slot->ends_at, 'status' => 'processing',
                'fulfillment_version' => $order->fulfillment_version + 1]);
            OrderStatusHistory::create([
                'order_id' => $order->id, 'from_status' => 'payment_received_delivery_review', 'to_status' => 'processing',
                'changed_by' => $actor->id, 'customer_notified' => false,
                'notes' => 'Delivery rebooked from '.$previousWindow.' to '.$order->delivery_window_label.'. Staff note: '.trim($note),
            ]);

            return $order->fresh();
        }, 3);
    }

    private function validateSlot(ShippingMethod $method, ?DeliverySlot $slot, bool $lock = false): void
    {
        if (! $method->is_active || ! $slot || $slot->shipping_method_id !== $method->id || ! $slot->is_active
            || ! $slot->starts_at->isFuture() || ! $slot->booking_closes_at->isFuture()
            || $this->available($slot, null, $lock) < 1) {
            $this->invalid('Choose an available delivery window. This window is missing, closed or fully booked.');
        }
    }

    private function snapshot(DeliverySlot $slot): array
    {
        return ['id' => $slot->id, 'starts_at' => $slot->starts_at->toIso8601String(),
            'ends_at' => $slot->ends_at->toIso8601String()];
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['delivery_slot_id' => $message]);
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Delivery booking changes require an order transaction.');
        }
    }
}
