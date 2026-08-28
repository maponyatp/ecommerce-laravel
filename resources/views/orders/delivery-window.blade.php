@if($order->delivery_window_label)
    <div class="mt-3 text-sm">
        <p class="font-semibold">Delivery window: {{ $order->delivery_window_label }}</p>
        @if($order->deliveryBooking?->status === 'confirmed')
            <p>Booking confirmed.</p>
        @elseif($order->status === 'payment_received_delivery_review')
            <p class="text-amber-700">Payment received, but this delivery window is not confirmed. Our team needs to arrange an available window with you. Please contact the shop; do not pay again.</p>
        @elseif($order->deliveryBooking?->status === 'held' && $order->deliveryBooking->expires_at?->isFuture())
            <p>Temporarily held until {{ $order->deliveryBooking->expires_at->copy()->timezone(config('commerce.delivery_timezone'))->format('d M Y H:i') }} South African time. Payment must be confirmed before the hold expires.</p>
        @else
            <p>This delivery window is not confirmed. Contact the shop if you have already paid.</p>
        @endif
    </div>
@endif
