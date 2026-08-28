<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderPaymentService
{
    public function markPaid(PaymentTransaction $transaction, array $gatewayPayload = []): Order
    {
        $order = DB::transaction(function () use ($transaction, $gatewayPayload) {
            // All payment/expiry/fulfilment paths lock the order first.
            $order = Order::query()->lockForUpdate()->findOrFail($transaction->order_id);
            $transaction = PaymentTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if ($transaction->status === 'paid') {
                return $order;
            }
            $transaction->update([
                'status' => 'paid', 'response_code' => $gatewayPayload['responseCode'] ?? $transaction->response_code,
                'response_payload' => $gatewayPayload, 'paid_at' => now(), 'failed_at' => null,
            ]);
            // A second settlement/replay must not reverse later fulfilment or refunds.
            if (in_array($order->payment_status, ['paid', 'refunded'], true) && $order->payment_processed_at) {
                return $order;
            }
            if ($order->payment_status === 'refunded') {
                return $order;
            }

            return $this->settleLocked($order);
        }, 3);

        DB::afterCommit(fn () => app(OrderReceiptService::class)->deliverFor($order));

        return $order->fresh();
    }

    public function settleFree(Order $order): Order
    {
        $order = DB::transaction(function () use ($order) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            if ((float) $order->total_amount !== 0.0 || $order->payment_method !== 'free') {
                throw new \LogicException('Only zero-total orders may use free settlement.');
            }
            if ($order->payment_processed_at) {
                return $order;
            }

            return $this->settleLocked($order);
        }, 3);
        DB::afterCommit(fn () => app(OrderReceiptService::class)->deliverFor($order));

        return $order->fresh();
    }

    private function settleLocked(Order $order): Order
    {
        $cancelled = $order->status === 'cancelled';
        if ($cancelled) {
            app(StockReservationService::class)->release($order);
            $stockCommitted = false;
        } else {
            $stockCommitted = app(StockReservationService::class)->commit($order);
        }
        $deliveryConfirmed = $stockCommitted && app(DeliverySchedulingService::class)->confirm($order);
        $order->update([
            'status' => $cancelled ? 'cancelled' : (! $stockCommitted ? 'payment_received_stock_review'
                : ($deliveryConfirmed ? 'processing' : 'payment_received_delivery_review')),
            'payment_status' => 'paid', 'payment_processed_at' => now(),
        ]);
        app(InvoiceDocumentService::class)->issue($order);
        app(DigitalFulfillmentService::class)->issue($order);
        if (! $cancelled) {
            app(OrderReceiptService::class)->enqueue($order);
        }
        if ($stockCommitted && ! $deliveryConfirmed) {
            Log::critical('Paid order requires delivery window review.', ['order_id' => $order->id]);
        }
        if (! $stockCommitted) {
            Log::critical('Paid order requires manual stock/cancellation review.', ['order_id' => $order->id]);
        }

        return $order;
    }

    public function markFailed(PaymentTransaction $transaction, array $gatewayPayload = []): void
    {
        DB::transaction(function () use ($transaction, $gatewayPayload) {
            $order = Order::query()->lockForUpdate()->findOrFail($transaction->order_id);
            $transaction = PaymentTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if ($transaction->status === 'paid') {
                return;
            }
            $transaction->update([
                'status' => 'failed', 'response_code' => $gatewayPayload['responseCode'] ?? null,
                'response_payload' => $gatewayPayload, 'failed_at' => now(),
            ]);
            if (! in_array($order->payment_status, ['paid', 'refunded'], true)) {
                app(StockReservationService::class)->release($order);
                $order->update(['status' => $order->status === 'cancelled' ? 'cancelled' : 'payment_failed', 'payment_status' => 'failed']);
            }
        }, 3);
    }
}
