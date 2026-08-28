<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderIssue;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderSupportService
{
    /** Controller must verify order ownership or the signed order-support link first. */
    public function open(Order $order, array $data, ?User $customer): OrderIssue
    {
        $data = Validator::make($data, [
            'submission_key' => 'required|uuid', 'category' => ['required', Rule::in(array_keys(OrderIssue::CATEGORIES))],
            'message' => 'required|string|min:10|max:4000',
            'order_item_id' => ['nullable', 'integer', Rule::exists('order_items', 'id')->where('order_id', $order->id)],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:9999', Rule::requiredIf(filled($data['order_item_id'] ?? null))],
        ])->validate();

        return DB::transaction(function () use ($order, $data, $customer) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            if ($existing = $order->issues()->where('submission_key', $data['submission_key'])->lockForUpdate()->first()) {
                return $existing;
            }
            if ($order->issues()->whereNotNull('active_order_id')->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['message' => 'This order already has an open support case. Reply to it below instead.']);
            }
            $item = filled($data['order_item_id'] ?? null) ? $order->items()->find($data['order_item_id']) : null;
            if (($item && $data['quantity'] > $item->quantity) || (! $item && filled($data['quantity'] ?? null))) {
                throw ValidationException::withMessages(['quantity' => 'Choose an order item and a quantity no greater than the quantity ordered.']);
            }
            $issue = $order->issues()->create([
                'active_order_id' => $order->id, 'submission_key' => $data['submission_key'],
                'category' => $data['category'],
                'order_item_id' => $item?->id, 'quantity' => $item ? $data['quantity'] : null,
                'status' => 'open',
            ]);
            $issue->messages()->create(['author_id' => $customer?->id, 'author_type' => 'customer',
                'body' => $data['message'], 'is_internal' => false]);

            return $issue;
        }, 3);
    }

    public function reply(Order $order, int $issueId, array $data, ?User $customer): OrderIssue
    {
        $data = Validator::make($data, ['submission_key' => 'required|uuid', 'message' => 'required|string|min:1|max:4000'])->validate();

        return DB::transaction(function () use ($order, $issueId, $data, $customer) {
            Order::lockForUpdate()->findOrFail($order->id);
            $issue = $order->issues()->lockForUpdate()->findOrFail($issueId);
            if ($issue->messages()->where('submission_key', $data['submission_key'])->lockForUpdate()->exists()) {
                return $issue;
            }
            if ($issue->status === 'resolved') {
                throw ValidationException::withMessages(['message' => 'This support case is closed. Open a new case if you need more help.']);
            }
            $issue->messages()->create(['author_id' => $customer?->id, 'author_type' => 'customer',
                'body' => $data['message'], 'is_internal' => false, 'submission_key' => $data['submission_key']]);
            $status = $issue->status === 'waiting_customer' ? 'investigating' : $issue->status;
            $this->recordStatus($issue, $status, $customer);
            $issue->update(['status' => $status, 'version' => $issue->version + 1]);

            return $issue->fresh();
        }, 3);
    }

    public function update(OrderIssue $issue, array $data, User $actor): OrderIssue
    {
        Gate::forUser($actor)->authorize('update', $issue);
        $data = Validator::make($data, [
            'version' => 'required|integer|min:0', 'status' => ['required', Rule::in(array_keys(OrderIssue::STATUSES))],
            'public_message' => 'nullable|string|max:4000', 'internal_note' => 'nullable|string|max:4000',
        ])->validate();

        return DB::transaction(function () use ($issue, $data, $actor) {
            // Consistent order-first lock ordering with customer submissions.
            Order::lockForUpdate()->findOrFail($issue->order_id);
            $issue = OrderIssue::lockForUpdate()->findOrFail($issue->id);
            if ($issue->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['status' => 'This case changed since you opened it. Reload before replying.']);
            }
            if ($issue->status === 'resolved') {
                throw ValidationException::withMessages(['status' => 'Closed cases are read-only. A customer can open a new case.']);
            }
            $status = $data['status'];
            if ($status === 'open' && $issue->status !== 'open') {
                throw ValidationException::withMessages(['status' => 'Choose Under review, Awaiting reply or Closed.']);
            }
            if ($status !== $issue->status && in_array($status, ['waiting_customer', 'resolved'], true)
                && blank($data['public_message'] ?? null)) {
                throw ValidationException::withMessages(['public_message' => 'Add a customer-visible explanation before requesting a reply or closing the case.']);
            }
            foreach (['public_message' => false, 'internal_note' => true] as $field => $internal) {
                if (filled($data[$field] ?? null)) {
                    $issue->messages()->create(['author_id' => $actor->id, 'author_type' => 'staff',
                        'body' => trim($data[$field]), 'is_internal' => $internal]);
                }
            }
            $this->recordStatus($issue, $status, $actor);
            $issue->update(['status' => $status, 'version' => $issue->version + 1,
                'active_order_id' => $status === 'resolved' ? null : $issue->order_id,
                'resolved_at' => $status === 'resolved' ? now() : null]);

            return $issue->fresh();
        }, 3);
    }

    private function recordStatus(OrderIssue $issue, string $status, ?User $actor): void
    {
        if ($issue->status !== $status) {
            $issue->messages()->create(['author_id' => $actor?->id, 'author_type' => 'system',
                'body' => 'Status changed: '.OrderIssue::STATUSES[$issue->status].' → '.OrderIssue::STATUSES[$status].'.',
                'is_internal' => false]);
        }
    }
}
