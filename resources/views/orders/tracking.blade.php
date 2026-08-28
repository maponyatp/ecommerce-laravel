@if($order->shipping_address)
<div class="mt-6 rounded-2xl border border-gray-200 bg-white p-6">
    <h2 class="font-semibold text-gray-950">Delivery progress</h2>
    @include('orders.delivery-window')
    <p class="mt-2 text-sm text-gray-600">{{ str($order->shipping_status)->replace('_', ' ')->title() }}</p>
    @if($order->delivery_carrier)<p class="mt-2 text-sm">Delivery team: {{ $order->delivery_carrier }}</p>@endif
    @if($order->supplier_tracking_number)<p class="mt-2 text-sm">Reference: {{ $order->supplier_tracking_number }}</p>@endif
    @if($order->safe_tracking_url)
        <a href="{{ $order->safe_tracking_url }}" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex text-sm font-semibold text-blue-700 underline">Track delivery</a>
    @endif
    @if($order->shipped_at)<p class="mt-2 text-sm text-gray-500">Dispatched {{ $order->shipped_at->format('d M Y, H:i') }}</p>@endif
    @if($order->delivered_at)<p class="mt-2 text-sm text-gray-500">Marked delivered {{ $order->delivered_at->format('d M Y, H:i') }}</p>@endif
</div>
@endif
