<?php

namespace App\Support;

use App\Models\Order;

class OrderWorkspace
{
    public const FULFILMENT_LABELS = ['unfulfilled' => 'Not started', 'processing' => 'Preparing', 'shipped' => 'Dispatched', 'delivered' => 'Delivered', 'not_required' => 'Digital / no delivery', 'returned' => 'Returned'];

    public static function status(?string $value): string
    {
        return match ($value) {
            'payment_received_stock_review' => 'Payment received · review stock',
            'payment_received_delivery_review' => 'Payment received · review delivery',
            'payment_review' => 'Check payment with provider',
            'checkout_expired' => 'Checkout expired',
            'awaiting_payment' => 'Awaiting payment',
            'pending' => 'Pending review',
            'processing' => 'In progress',
            default => ucfirst(str_replace('_', ' ', $value ?: 'Not recorded')),
        };
    }

    public static function nextStep(Order $order): string
    {
        if (in_array($order->status, ['cancelled', 'refunded'], true) || $order->payment_status === 'refunded') {
            return 'This order is closed for fulfilment. Use the separate returns or support workflow for follow-up.';
        }
        if ($order->payment_status !== 'paid') {
            return 'Wait for verified payment before preparing or dispatching. Review uncertain payments in Payment transactions; do not ask the customer to pay again until the provider status is confirmed.';
        }
        if ($order->hasPendingRefund()) {
            return 'Fulfilment is paused for a pending refund. Use Refunds & credits to record verified external completion or cancel the request if no refund occurred.';
        }
        if ($order->status === 'payment_received_delivery_review') {
            return 'Payment is received, but the delivery window is not confirmed. Agree an available window with the customer, then use Resolve delivery booking.';
        }
        if ($order->status === 'payment_received_stock_review' || ! $order->inventory_committed_at) {
            return 'Payment is received, but inventory needs review. Do not dispatch or charge again. Review stock and record a private staff note.';
        }

        return match ($order->shipping_status) {
            'unfulfilled' => 'Select Preparing and save to begin. Check the items, recipient and gift message before packing.',
            'processing' => 'When ready, enter the courier or local delivery team and a dispatch reference. Select Dispatched and save. This does not book a courier.',
            'shipped' => 'Confirm receipt with the courier or recipient, then select Delivered and save. This does not send a delivery email.',
            'delivered' => 'Delivery is complete. Use the invoice, customer history or returns controls for follow-up.',
            'not_required' => 'No physical delivery is required. Review receipt delivery and the customer’s digital access if they need help.',
            default => 'Review the order history and support cases before taking further action.',
        };
    }
}
