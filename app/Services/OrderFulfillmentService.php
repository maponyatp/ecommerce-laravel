<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderNote;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Support\OrderWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OrderFulfillmentService
{
    public const TRANSITIONS = ['unfulfilled' => ['processing'], 'processing' => ['shipped'], 'shipped' => ['delivered']];

    public function canFulfil(Order $order): bool
    {
        return $order->payment_status === 'paid' && $order->inventory_committed_at
            && ! $order->hasPendingRefund()
            && ! in_array($order->status, ['cancelled', 'refunded', 'payment_received_stock_review', 'payment_received_delivery_review'], true);
    }

    public function statusOptions(?Order $order): array
    {
        if (! $order) {
            return [];
        }
        $states = [$order->shipping_status];
        if ($this->canFulfil($order)) {
            $states = array_merge($states, self::TRANSITIONS[$order->shipping_status] ?? []);
        }

        return collect($states)->mapWithKeys(fn ($state) => [$state => OrderWorkspace::FULFILMENT_LABELS[$state] ?? ucfirst(str_replace('_', ' ', $state ?: 'Not recorded'))])->all();
    }

    public function update(Order $order, array $data, User $actor): Order
    {
        $this->authorize($order, $actor);
        $data = Validator::make($data, [
            'fulfillment_version' => 'required|integer|min:0',
            'shipping_status' => 'required|in:unfulfilled,processing,shipped,delivered,not_required,returned',
            'delivery_carrier' => 'nullable|string|max:120',
            'supplier_tracking_number' => 'nullable|string|max:255',
            'tracking_url' => 'nullable|url:https|max:2048',
        ])->validate();

        return DB::transaction(function () use ($order, $data, $actor) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->fulfillment_version !== (int) $data['fulfillment_version']) {
                throw ValidationException::withMessages(['shipping_status' => 'Another staff member updated this order. Reload before saving.']);
            }
            $changes = collect($data)->except('fulfillment_version')->all();
            $order->fill($changes);
            if (! $order->isDirty(array_keys($changes))) {
                return $order;
            }
            if (! $this->canFulfil($order)) {
                throw ValidationException::withMessages(['shipping_status' => 'Fulfilment requires verified payment and committed inventory. Resolve pending refunds, payment, cancellation, stock or delivery booking issues first.']);
            }
            $from = $order->getOriginal('shipping_status');
            $to = $order->shipping_status;
            $allowed = self::TRANSITIONS;
            if (($from !== $to && ! in_array($to, $allowed[$from] ?? [], true))
                || in_array($from, ['not_required', 'returned'], true)) {
                throw ValidationException::withMessages(['shipping_status' => 'Use the sequence Unfulfilled → Preparing → Dispatched → Delivered. Returns require a separate returns workflow.']);
            }
            if (in_array($to, ['shipped', 'delivered'], true)
                && (blank($order->delivery_carrier) || blank($order->supplier_tracking_number))) {
                throw ValidationException::withMessages(['supplier_tracking_number' => 'Enter the courier/local delivery team and a tracking or dispatch reference.']);
            }
            $previousStatus = $order->status;
            if ($from !== $to) {
                $order->status = $to === 'delivered' ? 'completed' : 'processing';
                if ($to === 'shipped') {
                    $order->shipped_at = now();
                }
                if ($to === 'delivered') {
                    $order->delivered_at = now();
                }
            }
            $order->fulfillment_version++;
            $order->save();
            OrderStatusHistory::create([
                'order_id' => $order->id, 'from_status' => $previousStatus, 'to_status' => $order->status,
                'changed_by' => $actor->id, 'notes' => $from === $to
                    ? 'Delivery tracking details updated.' : "Delivery: {$from} → {$to}.",
                'customer_notified' => false,
            ]);

            return $order->fresh();
        }, 3);
    }

    public function addInternalNote(Order $order, string $note, User $actor): OrderNote
    {
        $this->authorize($order, $actor);
        $data = Validator::make(['note' => trim($note)], ['note' => 'required|string|max:5000'])->validate();

        return OrderNote::createInternalNote($order->id, $data['note'], $actor->id);
    }

    private function authorize(Order $order, User $actor): void
    {
        abort_unless($actor->hasAnyRole(['admin', 'super_admin']), 403);
        Gate::forUser($actor)->authorize('update', $order);
    }
}
