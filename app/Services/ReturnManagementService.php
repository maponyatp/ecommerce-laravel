<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReturnManagementService
{
    public const STATUSES = ['pending' => 'Pending review', 'approved' => 'Approved / awaiting receipt',
        'rejected' => 'Rejected', 'cancelled' => 'Cancelled before receipt', 'received' => 'Received', 'completed' => 'Return handling complete'];

    public const METHODS = ['collection' => 'Arrange collection manually', 'drop_off' => 'Customer drop-off',
        'no_return_required' => 'No physical return required (staff decision)'];

    public const CONDITIONS = ['unopened' => 'Unopened', 'opened' => 'Opened', 'damaged' => 'Damaged / wilted'];

    public const DISPOSITIONS = ['quarantine' => 'Hold for inspection', 'discard' => 'Discard / not for resale', 'review' => 'Review separately — no automatic restock'];

    private function authorize(Order $order, User $actor): void
    {
        abort_unless($actor->hasAnyRole(['admin', 'super_admin']), 403);
        Gate::forUser($actor)->authorize('viewAny', Order::class);
        Gate::forUser($actor)->authorize('view', $order);
        Gate::forUser($actor)->authorize('update', $order);
    }

    private function invalid(string $message, string $field = 'reason'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public function snapshot(ReturnRequest $record): array
    {
        return ['status' => $record->status, 'method' => $record->return_method, 'resolution' => $record->resolution,
            'items' => $record->items()->orderBy('id')->get()->map(fn ($item) => $item->only([
                'order_item_id', 'product_name_snapshot', 'quantity', 'received_quantity', 'condition', 'disposition',
            ]))->all()];
    }

    public function open(Order $order, array $input, User $actor): ReturnRequest
    {
        $this->authorize($order, $actor);
        $data = Validator::make($input, [
            'request_key' => 'required|uuid', 'reason' => 'required|string|max:255', 'description' => 'nullable|string|max:4000',
            'return_method' => ['required', Rule::in(array_keys(self::METHODS))],
            'items' => 'required|array|min:1|max:50', 'items.*.order_item_id' => 'required|integer|min:1|distinct',
            'items.*.quantity' => 'required|integer|min:1|max:9999',
        ])->validate();
        $data['reason'] = trim($data['reason']);
        if ($data['reason'] === '') {
            $this->invalid('Describe the reason for the return.');
        }
        $data['description'] = trim($data['description'] ?? '');
        $items = collect($data['items'])->map(fn ($item) => ['order_item_id' => (int) $item['order_item_id'], 'quantity' => (int) $item['quantity']])
            ->sortBy('order_item_id')->values()->all();
        $fingerprint = hash('sha256', json_encode([$order->id, $data['reason'], $data['description'], $data['return_method'], $items]));

        return DB::transaction(function () use ($order, $data, $items, $fingerprint, $actor) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $this->authorize($order, $actor);
            $existing = ReturnRequest::where('request_key', $data['request_key'])->first();
            if ($existing) {
                if ($existing->order_id !== $order->id || $existing->request_fingerprint !== $fingerprint) {
                    $this->invalid('This request was already used with different values. Reopen the form.');
                }

                return $existing;
            }
            if (! in_array($order->payment_status, ['paid', 'refunded'], true) || ! $order->inventory_committed_at
                || ! in_array($order->shipping_status, ['shipped', 'delivered'], true)
                || in_array($order->status, ['cancelled', 'payment_review', 'payment_received_stock_review', 'payment_received_delivery_review'], true)) {
                $this->invalid('Returns require a paid/refunded order with committed inventory that has been dispatched or delivered.');
            }
            if (ReturnRequest::where('order_id', $order->id)->count() >= 100) {
                $this->invalid('This order has reached the 100-request limit. Review its existing returns.');
            }
            $lines = [];
            foreach ($items as $item) {
                $line = $order->items()->find($item['order_item_id']);
                if (! $line || $line->is_downloadable_snapshot !== false) {
                    $this->invalid('Choose physical order items with a recorded product type. Digital or legacy unknown-type items need separate review.', 'items');
                }
                $reserved = DB::table('return_request_items')->join('return_requests', 'return_request_items.return_request_id', '=', 'return_requests.id')
                    ->where('return_request_items.order_item_id', $line->id)->whereNotIn('return_requests.status', ['rejected', 'cancelled'])->sum('return_request_items.quantity');
                if ($line->quantity < $item['quantity'] + $reserved) {
                    $this->invalid('Requested quantities exceed the purchased quantity after earlier returns are counted.', 'items');
                }
                $lines[] = [...$item, 'product_name_snapshot' => $line->product_name_snapshot ?: 'Order item #'.$line->id, 'received_quantity' => 0];
            }
            $record = ReturnRequest::create(['order_id' => $order->id, 'customer_id' => $order->user_id,
                'rma_number' => 'RMA-'.Str::upper((string) Str::uuid()), 'reason' => $data['reason'], 'description' => $data['description'],
                'status' => 'pending', 'return_method' => $data['return_method'], 'version' => 1, 'created_by' => $actor->id,
                'request_key' => $data['request_key'], 'request_fingerprint' => $fingerprint]);
            $record->items()->createMany($lines);
            $record->changes()->create(['actor_id' => $actor->id, 'version' => 1, 'before_values' => null,
                'after_values' => $this->snapshot($record), 'note' => 'Return request recorded by staff.']);

            return $record->fresh();
        }, 3);
    }

    public function update(ReturnRequest $record, array $input, User $actor): ReturnRequest
    {
        $this->authorize($record->order, $actor);
        $data = Validator::make($input, ['version' => 'required|integer|min:1', 'status' => ['required', Rule::in(array_keys(self::STATUSES))],
            'note' => 'required|string|max:4000', 'items' => 'required|array|min:1|max:50',
            'items.*.id' => 'required|integer|min:1|distinct', 'items.*.received_quantity' => 'required|integer|min:0|max:9999',
            'items.*.condition' => ['nullable', Rule::in(array_keys(self::CONDITIONS))],
            'items.*.disposition' => ['nullable', Rule::in(array_keys(self::DISPOSITIONS))],
        ])->validate();
        $data['note'] = trim($data['note']);
        if ($data['note'] === '') {
            $this->invalid('Enter a decision or handling note.', 'note');
        }

        return DB::transaction(function () use ($record, $data, $actor) {
            $order = Order::lockForUpdate()->findOrFail($record->order_id);
            $this->authorize($order, $actor);
            $record = ReturnRequest::where('order_id', $order->id)->lockForUpdate()->findOrFail($record->id);
            if ($record->version === null || $record->version !== (int) $data['version']) {
                $this->invalid('This return is legacy data or has changed. Reload before saving; legacy records need reconciliation.', 'status');
            }
            $allowed = ['pending' => ['pending', 'approved', 'rejected', 'cancelled'], 'approved' => ['approved', 'received', 'completed', 'cancelled'],
                'received' => ['received', 'completed']];
            if (! in_array($data['status'], $allowed[$record->status] ?? [], true)) {
                $this->invalid('That status transition is not allowed. Completed/rejected/cancelled returns are locked.', 'status');
            }
            $before = $this->snapshot($record);
            $lines = $record->items()->lockForUpdate()->get()->keyBy('id');
            if ($lines->count() !== count($data['items'])) {
                $this->invalid('Submit every line in this return exactly once.', 'items');
            }
            foreach ($data['items'] as $item) {
                $line = $lines->get((int) $item['id']);
                if (! $line || $item['received_quantity'] > $line->quantity || $item['received_quantity'] < $line->received_quantity) {
                    $this->invalid('Receipt quantities must belong to this return, cannot decrease, and cannot exceed the approved quantity.', 'items');
                }
                $received = (int) $item['received_quantity'];
                if ($received > 0 && ($record->status === 'pending' || $record->return_method === 'no_return_required')) {
                    $this->invalid('Approve the return before recording receipt. No-return-required cases cannot receive items.', 'items');
                }
                if ($received > 0 && (empty($item['condition']) || empty($item['disposition']))) {
                    $this->invalid('Record the condition and handling decision for every received item.', 'items');
                }
                $line->update(['received_quantity' => $received, 'condition' => $item['condition'] ?? null, 'disposition' => $item['disposition'] ?? null]);
            }
            $fullyReceived = $lines->every(fn ($line) => $line->received_quantity === $line->quantity);
            if ($data['status'] === 'cancelled' && $lines->sum('received_quantity') > 0) {
                $this->invalid('A return with received items cannot be cancelled. Complete the handling record instead.', 'status');
            }
            if ($data['status'] === 'received' && ! $fullyReceived) {
                $this->invalid('All approved quantities must be received before marking the return received.', 'status');
            }
            if ($data['status'] === 'completed' && $record->status !== 'received' && $record->return_method !== 'no_return_required') {
                $this->invalid('Record full receipt before completing return handling.', 'status');
            }
            if ($record->status === 'pending' && $data['status'] === 'approved') {
                $record->approved_by = $actor->id;
                $record->approved_at = now();
            }
            if ($data['status'] === 'received' && ! $record->received_at) {
                $record->received_at = now();
            }
            $record->status = $data['status'];
            $record->resolution = $data['note'];
            $record->version++;
            $record->save();
            $record->changes()->create(['actor_id' => $actor->id, 'version' => $record->version,
                'before_values' => $before, 'after_values' => $this->snapshot($record), 'note' => $data['note']]);

            return $record->fresh();
        }, 3);
    }
}
