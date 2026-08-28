<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentStatusCheck;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\OrderPaymentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class IkhokhaReconciliationService
{
    public function check(PaymentTransaction $transaction, User $actor): string
    {
        abort_unless($actor->hasAnyRole(['admin', 'super_admin']), 403);
        $transaction = $transaction->fresh();
        Gate::forUser($actor)->authorize('viewAny', Order::class);
        Gate::forUser($actor)->authorize('view', $transaction->order);
        Gate::forUser($actor)->authorize('update', $transaction->order);
        $gateway = app(IkhokhaGateway::class);
        if (! $gateway->isConfigured() || $transaction->gateway !== 'ikhokha'
            || ! $gateway->validReference($transaction->gateway_reference)) {
            throw ValidationException::withMessages(['payment' => 'Configure iKhokha and use a saved provider reference. Missing references require merchant-dashboard review.']);
        }
        if ($transaction->status === 'paid') {
            return 'already_paid';
        }

        $lock = Cache::lock('payment-status-check:'.$transaction->id, 30);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['payment' => 'A payment check is already running. Wait before checking again.']);
        }
        try {
            if ($transaction->statusChecks()->where('created_at', '>', now()->subMinute())->exists()) {
                throw ValidationException::withMessages(['payment' => 'Wait one minute between provider checks.']);
            }
            // Network I/O must not hold database/order/inventory locks.
            try {
                $payload = $gateway->paymentStatus($transaction->gateway_reference);
            } catch (\RuntimeException $exception) {
                $this->record($transaction, $actor, 'provider_unavailable');

                return 'provider_unavailable';
            }

            return DB::transaction(function () use ($transaction, $actor, $payload) {
                $order = Order::lockForUpdate()->findOrFail($transaction->order_id);
                $current = PaymentTransaction::lockForUpdate()->findOrFail($transaction->id);
                $outcome = 'unconfirmed';
                if ($current->status === 'paid') {
                    $outcome = 'already_paid';
                } elseif ($current->gateway !== 'ikhokha' || $current->gateway_reference !== $transaction->gateway_reference
                    || $current->external_transaction_id !== $transaction->external_transaction_id
                    || $current->amount !== $transaction->amount || $current->currency !== $transaction->currency) {
                    $outcome = 'stale';
                } elseif ($current->currency !== 'ZAR' || $order->currency !== 'ZAR'
                    || $order->payment_method !== 'ikhokha' || $current->amount !== $order->total_amount
                    || $payload['amount'] !== (int) round(((float) $current->amount) * 100)) {
                    $outcome = 'mismatch';
                } elseif ($payload['status'] === 'PAID') {
                    // Shared settlement preserves cancelled/refunded orders and commits inventory once.
                    app(OrderPaymentService::class)->markPaid($current, $payload + ['source' => 'status_lookup']);
                    $outcome = 'paid';
                }
                $this->record($current, $actor, $outcome, $payload);

                return $outcome;
            }, 3);
        } finally {
            $lock->release();
        }
    }

    private function record(PaymentTransaction $transaction, User $actor, string $outcome, array $payload = []): void
    {
        PaymentStatusCheck::create([
            'payment_transaction_id' => $transaction->id, 'checked_by' => $actor->id,
            'outcome' => $outcome, 'provider_status' => $payload['status'] ?? null,
            'amount_minor' => $payload['amount'] ?? null,
        ]);
    }
}
