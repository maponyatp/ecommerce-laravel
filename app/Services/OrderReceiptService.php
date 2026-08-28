<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderReceipt;
use App\Notifications\OrderConfirmationNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class OrderReceiptService
{
    /** Insert inside the same transaction as payment/invoice commitment. */
    public function enqueue(Order $order): ?OrderReceipt
    {
        if ($order->confirmation_sent_at) {
            return null; // Do not resend historical queued receipts automatically.
        }

        return OrderReceipt::firstOrCreate(['order_id' => $order->id], [
            'recipient' => $order->customer_email, 'status' => 'pending', 'available_at' => now(),
        ]);
    }

    public function deliverFor(Order $order): void
    {
        $receipt = OrderReceipt::where('order_id', $order->id)->first();
        if ($receipt) {
            $this->deliver($receipt->id);
        }
    }

    public function deliver(int $id): void
    {
        $receipt = DB::transaction(function () use ($id) {
            $row = OrderReceipt::lockForUpdate()->find($id);
            if (! $row || in_array($row->status, ['sent', 'failed', 'suppressed'], true)
                || ($row->available_at && $row->available_at->isFuture())
                || ($row->status === 'sending' && $row->locked_at?->gt(now()->subMinutes(10)))) {
                return null;
            }
            if ($row->attempts >= 10) {
                $row->update(['status' => 'failed', 'last_error' => 'Automatic retry limit reached. Review and retry from the order.']);

                return null;
            }
            $row->update(['status' => 'sending', 'attempts' => $row->attempts + 1,
                'locked_at' => now(), 'lock_token' => (string) Str::uuid()]);

            return $row;
        }, 3);
        if (! $receipt) {
            return;
        }

        try {
            $order = $receipt->order()->with(['items.product', 'shippingMethod'])->firstOrFail();
            if ($order->payment_status !== 'paid' || in_array($order->status, ['cancelled', 'refunded'], true)) {
                $receipt->update(['status' => 'suppressed', 'lock_token' => null, 'locked_at' => null]);

                return;
            }
            // Explicitly deliver now: merely enqueuing ShouldQueue is not an SMTP acceptance.
            Notification::sendNow((new AnonymousNotifiable)->route('mail', $receipt->recipient), new OrderConfirmationNotification($order));
            DB::transaction(function () use ($receipt, $order) {
                Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $updated = OrderReceipt::whereKey($receipt->id)->where('lock_token', $receipt->lock_token)
                    ->update(['status' => 'sent', 'sent_at' => now(), 'locked_at' => null, 'lock_token' => null, 'last_error' => null]);
                if ($updated) {
                    Order::whereKey($order->id)->update(['confirmation_sent_at' => now()]);
                }
            });
        } catch (\Throwable $exception) {
            OrderReceipt::whereKey($receipt->id)->where('lock_token', $receipt->lock_token)->update([
                'status' => $receipt->attempts >= 10 ? 'failed' : 'pending',
                'available_at' => now()->addMinutes(min(60, 2 ** min($receipt->attempts, 6))),
                'locked_at' => null, 'lock_token' => null,
                'last_error' => 'Receipt delivery failed ('.class_basename($exception).').',
            ]);
            Log::warning('Order receipt retained for retry.', ['order_id' => $receipt->order_id, 'exception_type' => get_class($exception)]);
        }
    }

    public function retryFailed(Order $order): bool
    {
        return (bool) OrderReceipt::where('order_id', $order->id)->where('status', 'failed')
            ->update(['status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'last_error' => null]);
    }

    public function recover(int $limit = 100): int
    {
        $ids = OrderReceipt::where(function ($query) {
            $query->where(fn ($pending) => $pending->where('status', 'pending')->where('available_at', '<=', now()))
                ->orWhere(fn ($stuck) => $stuck->where('status', 'sending')->where('locked_at', '<=', now()->subMinutes(10)));
        })->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $this->deliver($id);
        }

        return $ids->count();
    }
}
