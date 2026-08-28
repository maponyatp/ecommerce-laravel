@if($order->delivery_window_label)
    <div class="mt-3 text-sm">
        <p class="font-semibold">Delivery window: {{ $order->delivery_window_label }}</p>
        @if(\App\Support\CustomerOrderStatus::describe($order)['confirmed'] && \App\Support\CustomerOrderStatus::hasConfirmedDeliveryBooking($order))
            <p>Booking confirmed.</p>
        @elseif($order->payment_status === 'paid' && ! in_array($order->status, ['cancelled', 'refunded'], true))
            <p class="text-amber-700">Payment received, but this delivery window is not confirmed. Our team needs to arrange an available window with you. Please contact the shop; do not pay again.</p>
        @elseif($order->payment_status === 'pending' && ! in_array($order->status, ['cancelled', 'refunded', 'checkout_expired'], true) && $order->deliveryBooking?->status === 'held' && $order->deliveryBooking->expires_at?->isFuture() && (int) $order->deliveryBooking->delivery_slot_id === (int) $order->delivery_slot_id)
            <p>Temporarily held until {{ $order->deliveryBooking->expires_at->copy()->timezone(config('commerce.delivery_timezone'))->format('d M Y H:i') }} South African time. Payment must be confirmed before the hold expires.</p>
        @else
            <p>This delivery window is not confirmed. Contact the shop if you have already paid.</p>
        @endif
    </div>
@endif
