@extends('layouts.app')

@section('title', 'Order Confirmation')

@section('content')
@php($customerStatus = \App\Support\CustomerOrderStatus::describe($order))
<div class="container mx-auto px-4 py-12">
    <div class="max-w-2xl mx-auto">
        @if($order->payment_status === 'pending' && $order->stock_reservation_status === 'held' && $order->stock_reserved_until?->isFuture() && ! in_array($order->status, ['cancelled', 'refunded', 'checkout_expired'], true))
            <p class="mb-4 text-sm text-gray-600">Stock is reserved until {{ $order->stock_reserved_until->format('d M Y H:i') }} ({{ config('app.timezone') }}). Payment after this time is subject to stock review.</p>
        @endif
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 {{ $customerStatus['confirmed'] ? 'bg-green-100' : 'bg-amber-100' }} rounded-full mb-4">
                <svg aria-hidden="true" class="w-8 h-8 {{ $customerStatus['confirmed'] ? 'text-green-600' : 'text-amber-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    @if($customerStatus['confirmed'])
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    @else
                        <circle cx="12" cy="12" r="9" stroke-width="2"></circle>
                        <path stroke-linecap="round" stroke-width="2" d="M12 7v6m0 4h.01"></path>
                    @endif
                </svg>
            </div>
            <h1 class="text-3xl font-bold text-gray-900 mb-2">{{ $customerStatus['title'] }}</h1>
            <p class="text-gray-600" role="status">{{ $customerStatus['message'] }}</p>
        </div>

        <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Order Details</h2>
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <span class="text-gray-500">Order Number:</span>
                    <span class="font-medium text-gray-900 ml-1">#{{ $order->id }}</span>
                </div>
                <div>
                    <span class="text-gray-500">Date:</span>
                    <span class="font-medium text-gray-900 ml-1">{{ $order->created_at->format('M d, Y') }}</span>
                </div>
                <div>
                    <span class="text-gray-500">Email:</span>
                    <span class="font-medium text-gray-900 ml-1">{{ $order->customer_email }}</span>
                </div>
                <div>
                    <span class="text-gray-500">Status:</span>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $customerStatus['confirmed'] ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }} ml-1">
                        {{ str($order->status)->replace('_', ' ')->title() }}
                    </span>
                </div>
            </div>
        </div>

        <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Order Summary</h2>
            <div class="divide-y divide-gray-200">
                @foreach($order->items as $item)
                    <div class="py-3 flex justify-between items-center">
                        <div>
                            <span class="font-medium text-gray-900">{{ $item->product_name_snapshot ?? $item->product->name ?? 'Product #' . $item->product_id }}</span>
                            <span class="text-gray-500 text-sm ml-2">× {{ $item->quantity }}</span>
                            @if($item->product?->is_downloadable)
                                <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">Digital</span>
                            @endif
                        </div>
                        <span class="font-medium text-gray-900">{{ $order->formatMoney($item->price * $item->quantity) }}</span>
                    </div>
                @endforeach
            </div>
            <div class="pt-4 mt-2 space-y-2 text-sm">
                <div class="flex justify-between text-gray-600">
                    <span>Subtotal</span>
                    <span>{{ $order->formatMoney($order->total_amount - $order->shipping_cost - $order->tax_amount + ($order->discount_amount ?? 0)) }}</span>
                </div>
                @if($order->discount_amount > 0)
                    <div class="flex justify-between text-green-600">
                        <span>Discount {{ $order->coupon_code ? '(' . $order->coupon_code . ')' : '' }}</span>
                        <span>-{{ $order->formatMoney($order->discount_amount) }}</span>
                    </div>
                @endif
                @if($order->shipping_cost > 0)
                    <div class="flex justify-between text-gray-600">
                        <span>Shipping</span>
                        <span>{{ $order->formatMoney($order->shipping_cost) }}</span>
                    </div>
                @endif
                @if($order->tax_amount > 0)
                    <div class="flex justify-between text-gray-600">
                        <span>Tax</span>
                        <span>{{ $order->formatMoney($order->tax_amount) }}</span>
                    </div>
                @endif
                <div class="flex justify-between font-bold text-gray-900 text-base pt-2 border-t border-gray-200">
                    <span>Total</span>
                    <span>{{ $order->formatMoney($order->total_amount) }}</span>
                </div>
            </div>
        </div>

        @include('orders.downloads', ['order' => $order])
        @include('orders.tracking', ['order' => $order])
        @include('orders.support-link', ['order' => $order])

        @if($order->shipping_address)
            <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-6 mb-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-2">Shipping Address</h2>
                <p class="text-gray-600">{{ $order->shipping_address }}</p>
            </div>
        @endif

        <div class="flex flex-col sm:flex-row gap-4 justify-center">
            @if($order->invoice)
                <a href="{{ URL::temporarySignedRoute('invoices.print', now()->addDays(30), ['invoice' => $order->invoice->id]) }}" class="text-white bg-gray-900 hover:bg-gray-800 font-medium rounded-lg px-6 py-3 text-center">View invoice</a>
            @elseif($customerStatus['refresh'])
                <button type="button" onclick="window.location.reload()" class="text-white bg-amber-600 hover:bg-amber-700 font-medium rounded-lg px-6 py-3 text-center">Check payment status</button>
            @endif
            @auth
                @if($order->isAccessibleTo(auth()->user()))
                    <a href="{{ route('orders.show', $order->id) }}" class="text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 font-medium rounded-lg px-6 py-3 text-center">View order</a>
                @endif
            @endauth
            <a href="{{ route('products.index') }}" class="text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 font-medium rounded-lg px-6 py-3 text-center">
                Continue Shopping
            </a>
        </div>
    </div>
</div>
@endsection
