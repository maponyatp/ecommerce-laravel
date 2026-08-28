<?php

namespace App\Support;

use App\Models\Order;

class CustomerOrderStatus
{
    /** @return array{title: string, message: string, confirmed: bool, refresh: bool} */
    public static function describe(Order $order): array
    {
        if ($order->payment_status === 'refunded' || $order->status === 'refunded') {
            return self::status('Refund recorded', 'This order is marked as refunded. This status does not verify when funds reach your account. Contact the store for the refund reference.');
        }

        if ($order->status === 'cancelled') {
            return self::status('Order cancelled', $order->payment_status === 'paid'
                ? 'This order is cancelled, but cancellation does not return money. Contact the store to confirm any refund arrangements; do not pay again.'
                : 'This order is cancelled. Cancellation does not verify an external payment or refund. If you paid, contact the store before paying again.');
        }

        if ($order->payment_status === 'paid') {
            if ($order->status === 'payment_received_stock_review' || ! $order->inventory_committed_at) {
                return self::status('Payment received — stock review required', 'Payment was received, but stock needs manual review. Contact the store to confirm availability; do not pay again.');
            }

            if ($order->status === 'payment_received_delivery_review'
                || (($order->delivery_slot_id || $order->delivery_window_label) && ! self::hasConfirmedDeliveryBooking($order))) {
                return self::status('Payment received — delivery review required', 'Payment was received, but your delivery window is not confirmed. Contact the store to arrange an available window; do not pay again.');
            }

            if (! in_array($order->status, ['processing', 'completed', 'paid'], true)) {
                return self::status('Payment received — order review required', 'Payment was received, but the fulfilment status needs review. Contact the store for an update; do not pay again.');
            }

            return self::status('Order confirmed!', 'Your order has been confirmed. View your order and delivery details below.', confirmed: true);
        }

        if ($order->payment_status === 'failed') {
            return self::status('Payment unsuccessful', 'Payment was not confirmed. If your bank shows a charge, contact the store before trying another payment.');
        }

        if ($order->status === 'checkout_expired' || $order->stock_reserved_until?->isPast()) {
            return self::status('Reservation expired', 'The stock reservation has expired. This does not cancel an external payment link. If you paid, contact the store before paying again.', refresh: true);
        }

        if ($order->status === 'payment_review') {
            return self::status('Payment verification required', 'Payment needs verification. Contact the store before starting another payment.', refresh: true);
        }

        return self::status('Payment pending', 'Payment is not confirmed yet. If you paid, check the status again shortly or contact the store before paying again.', refresh: true);
    }

    public static function hasConfirmedDeliveryBooking(Order $order): bool
    {
        $booking = $order->deliveryBooking;

        return $order->delivery_slot_id && $booking?->status === 'confirmed'
            && (int) $booking->delivery_slot_id === (int) $order->delivery_slot_id;
    }

    private static function status(string $title, string $message, bool $confirmed = false, bool $refresh = false): array
    {
        return compact('title', 'message', 'confirmed', 'refresh');
    }
}
