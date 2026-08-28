@extends('layouts.app')

@section('title', 'Order #'.$order->id)

@section('content')
<div class="mx-auto max-w-4xl px-4 py-10 sm:px-6">
    <div class="mb-7 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
        <div><a href="{{ route('orders.index') }}" class="text-sm font-medium text-gray-500 hover:text-gray-900">&larr; All orders</a><h1 class="mt-3 text-3xl font-bold text-gray-950">Order #{{ $order->id }}</h1><p class="mt-1 text-sm text-gray-500">Placed {{ $order->created_at->format('d F Y, H:i') }}</p></div>
        <div class="flex gap-2"><span class="rounded-full bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-700">{{ str($order->status)->replace('_', ' ')->title() }}</span><span class="rounded-full {{ $order->payment_status === 'paid' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }} px-3 py-1.5 text-xs font-semibold">Payment {{ ucfirst($order->payment_status) }}</span></div>
    </div>

    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-200 px-6 py-5"><h2 class="font-semibold text-gray-950">Items</h2></div>
        <div class="divide-y divide-gray-100 px-6">
            @foreach($order->items as $item)
            <div class="flex items-center justify-between gap-4 py-5"><div><p class="font-medium text-gray-900">{{ $item->product_name_snapshot ?? $item->product->name ?? 'Product #'.$item->product_id }}</p><p class="mt-1 text-sm text-gray-500">Quantity {{ $item->quantity }}</p></div><p class="font-semibold text-gray-950">{{ $order->formatMoney($item->price * $item->quantity) }}</p></div>
            @endforeach
        </div>
        <div class="border-t border-gray-200 bg-gray-50 px-6 py-5">
            <div class="ml-auto max-w-sm space-y-2 text-sm"><div class="flex justify-between text-gray-600"><span>Shipping</span><span>{{ $order->formatMoney($order->shipping_cost) }}</span></div><div class="flex justify-between text-gray-600"><span>Tax</span><span>{{ $order->formatMoney($order->tax_amount) }}</span></div><div class="flex justify-between border-t border-gray-200 pt-3 text-lg font-bold text-gray-950"><span>Total</span><span>{{ $order->formatMoney($order->total_amount) }}</span></div></div>
        </div>
    </div>

    @include('orders.downloads', ['order' => $order])
    @include('orders.tracking', ['order' => $order])
    @include('orders.support-link', ['order' => $order])

    <div class="mt-6 grid gap-5 sm:grid-cols-2">
        <div class="rounded-2xl border border-gray-200 bg-white p-6"><h2 class="font-semibold text-gray-950">Delivery</h2><p class="mt-3 text-sm text-gray-600">{{ str($order->shipping_status)->replace('_', ' ')->title() }}</p>@if($order->shipping_address)<p class="mt-2 whitespace-pre-line text-sm text-gray-600">{{ $order->shipping_address }}</p>@endif @if($order->supplier_tracking_number)<p class="mt-3 text-sm"><span class="text-gray-500">Tracking:</span> <span class="font-medium">{{ $order->supplier_tracking_number }}</span></p>@endif</div>
        <div class="rounded-2xl border border-gray-200 bg-white p-6"><h2 class="font-semibold text-gray-950">Documents</h2>@if($order->invoice)<a href="{{ URL::temporarySignedRoute('invoices.print', now()->addDays(30), ['invoice' => $order->invoice->id]) }}" class="mt-4 inline-flex rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">View invoice</a>@else<p class="mt-3 text-sm text-gray-500">Your invoice will appear after payment is confirmed.</p>@endif</div>
    </div>
</div>
@endsection
