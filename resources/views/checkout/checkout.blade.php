@extends('layouts.app')

@section('title', 'Checkout')

@section('content')
<div class="min-h-screen bg-gray-50 py-8">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Page Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">Checkout</h1>
            <p class="text-gray-500 mt-1">Prices and payments are in {{ \App\Support\StoreMoney::currency() }}. International buyers can order for the supported delivery destinations shown below. Your bank may apply conversion fees.</p>
        </div>

        <!-- Progress Steps -->
        <div class="flex items-center justify-center mb-8">
            <div class="flex items-center space-x-4 text-sm">
                <div class="flex items-center text-gray-400">
                    <div class="w-8 h-8 rounded-full bg-green-500 flex items-center justify-center mr-2">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                    <span class="text-green-600 font-medium">Cart</span>
                </div>
                <div class="w-12 h-0.5 bg-indigo-600"></div>
                <div class="flex items-center text-indigo-600">
                    <div class="w-8 h-8 rounded-full bg-indigo-600 flex items-center justify-center mr-2">
                        <span class="text-white font-bold text-xs">2</span>
                    </div>
                    <span class="font-semibold">Checkout</span>
                </div>
                <div class="w-12 h-0.5 bg-gray-300"></div>
                <div class="flex items-center text-gray-400">
                    <div class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center mr-2">
                        <span class="text-gray-500 font-bold text-xs">3</span>
                    </div>
                    <span>Confirmation</span>
                </div>
            </div>
        </div>

        <!-- Validation Errors -->
        @if ($errors->any())
            <div class="mb-6 bg-red-50 border border-red-200 rounded-lg p-4">
                <div class="flex items-start">
                    <svg class="w-5 h-5 text-red-500 mt-0.5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                    </svg>
                    <div>
                        <h3 class="text-sm font-semibold text-red-800 mb-1">Please fix the following errors:</h3>
                        <ul class="list-disc list-inside space-y-1">
                            @foreach ($errors->all() as $error)
                                <li class="text-sm text-red-700">{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif

        <div class="lg:grid lg:grid-cols-12 lg:gap-8">

            <!-- Main Form (Left Column) -->
            <div class="lg:col-span-7">
                <form action="{{ route('checkout.process') }}" method="POST" id="checkout-form">
                    @csrf
                    <input type="hidden" name="has_physical_products" value="{{ $hasPhysicalProducts ? 1 : 0 }}">
                    <input type="hidden" name="shipping_cost" id="shipping_cost_input" value="0.00">

                    <!-- Contact Information -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
                        <div class="px-6 py-4 border-b border-gray-100">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-full bg-indigo-100 flex items-center justify-center mr-3">
                                    <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                    </svg>
                                </div>
                                <h2 class="text-lg font-semibold text-gray-900">Contact Information</h2>
                            </div>
                        </div>
                        <div class="px-6 py-5">
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700 mb-1.5">
                                    Email Address <span class="text-red-500">*</span>
                                </label>
                                <input
                                    type="email"
                                    id="email"
                                    name="email"
                                    value="{{ old('email', auth()->user()?->email) }}"
                                    required
                                    placeholder="you@example.com"
                                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-colors @error('email') border-red-500 @enderror"
                                >
                                @error('email')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </div>

                    @if($hasPhysicalProducts)
                    @include('checkout.billing-details')

                    <!-- Shipping Information -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
                        <div class="px-6 py-4 border-b border-gray-100">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-full bg-indigo-100 flex items-center justify-center mr-3">
                                    <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    </svg>
                                </div>
                                <h2 class="text-lg font-semibold text-gray-900">Shipping Information</h2>
                            </div>
                        </div>
                        <div class="px-6 py-5 space-y-5">
                            <p class="text-sm text-gray-600">Enter the flower recipient's delivery address, which may differ from your own address.</p>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div><label for="delivery_contact_name" class="block text-sm font-medium text-gray-700">Recipient name *</label><input id="delivery_contact_name" name="delivery_contact_name" value="{{ old('delivery_contact_name') }}" autocomplete="shipping name" required maxlength="120" class="mt-1 w-full rounded-lg border-gray-300"></div>
                                <div><label for="delivery_phone" class="block text-sm font-medium text-gray-700">Recipient phone *</label><input type="tel" id="delivery_phone" name="delivery_phone" value="{{ old('delivery_phone') }}" autocomplete="shipping tel" placeholder="+27 82 123 4567" required maxlength="32" class="mt-1 w-full rounded-lg border-gray-300"></div>
                                <div><label for="shipping_country" class="block text-sm font-medium text-gray-700">Delivery country *</label><select id="shipping_country" name="shipping_country" autocomplete="shipping country" required class="mt-1 w-full rounded-lg border-gray-300">@foreach(config('commerce.delivery_countries') as $code => $name)<option value="{{ $code }}" @selected(old('shipping_country', 'ZA') === $code)>{{ $name }}</option>@endforeach</select></div>
                                <div><label for="shipping_city" class="block text-sm font-medium text-gray-700">City / town *</label><input id="shipping_city" name="shipping_city" value="{{ old('shipping_city') }}" autocomplete="shipping address-level2" required maxlength="120" class="mt-1 w-full rounded-lg border-gray-300"></div>
                                <div><label for="shipping_region" class="block text-sm font-medium text-gray-700">Province / region</label><input id="shipping_region" name="shipping_region" value="{{ old('shipping_region') }}" autocomplete="shipping address-level1" maxlength="120" class="mt-1 w-full rounded-lg border-gray-300"></div>
                                <div><label for="shipping_postal_code" class="block text-sm font-medium text-gray-700">Postal code *</label><input id="shipping_postal_code" name="shipping_postal_code" value="{{ old('shipping_postal_code') }}" autocomplete="shipping postal-code" required maxlength="20" class="mt-1 w-full rounded-lg border-gray-300"></div>
                            </div>
                            <div>
                                <label for="shipping_address" class="block text-sm font-medium text-gray-700 mb-1.5">
                                    Shipping Address <span class="text-red-500">*</span>
                                </label>
                                <textarea
                                    id="shipping_address"
                                    name="shipping_address"
                                    required
                                    rows="3"
                                    placeholder="Street address, building, apartment or delivery instructions"
                                    autocomplete="shipping street-address"
                                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-colors resize-none @error('shipping_address') border-red-500 @enderror"
                                >{{ old('shipping_address') }}</textarea>
                                @error('shipping_address')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="shipping_method" class="block text-sm font-medium text-gray-700 mb-1.5">
                                    Shipping Method <span class="text-red-500">*</span>
                                </label>
                                <div class="space-y-2">
                                    @foreach($shippingMethods as $method)
                                        <label class="flex items-center justify-between p-4 border border-gray-200 rounded-lg cursor-pointer hover:border-indigo-300 hover:bg-indigo-50 transition-colors has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50">
                                            <div class="flex items-center">
                                                <input
                                                    type="radio"
                                                    name="shipping_method_id"
                                                    id="shipping_method_{{ $method->id }}"
                                                    value="{{ $method->id }}"
                                                    data-base-rate="{{ $method->base_rate }}"
                                                    class="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500"
                                                    @checked((string) old('shipping_method_id', $shippingMethods->first()?->id) === (string) $method->id)
                                                >
                                                <div class="ml-3">
                                                    <span class="text-sm font-medium text-gray-900">{{ $method->name }}</span>
                                                    @if(isset($method->estimated_delivery_time))
                                                        <span class="text-xs text-gray-500 ml-2">{{ $method->estimated_delivery_time }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                            <span class="text-sm font-semibold text-gray-900">From {{ \App\Support\StoreMoney::format($method->base_rate) }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('shipping_method_id')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            @include('checkout.delivery-windows')

                            <!-- Drop Shipping Option -->
                            <div class="pt-2 border-t border-gray-100">
                                <label class="flex items-start cursor-pointer group">
                                    <div class="flex items-center h-5 mt-0.5">
                                        <input
                                            type="checkbox"
                                            id="dropship"
                                            name="dropship"
                                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                        >
                                    </div>
                                    <div class="ml-3">
                                        <span class="text-sm font-medium text-gray-700 group-hover:text-gray-900">Ship directly to recipient</span>
                                        <p class="text-xs text-gray-500 mt-0.5">Enable drop shipping to send directly to a third party</p>
                                    </div>
                                </label>
                            </div>

                            <!-- Dropship Supplier (hidden by default) -->
                            <div id="dropship-supplier" class="hidden pt-2">
                                <label for="supplier_id" class="block text-sm font-medium text-gray-700 mb-1.5">Select Supplier</label>
                                <select
                                    id="supplier_id"
                                    name="supplier_id"
                                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-colors"
                                >
                                    @foreach(config('dropshipping.suppliers') as $supplierId => $supplier)
                                        <option value="{{ $supplierId }}">{{ $supplier['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <!-- Recipient Info (hidden by default) -->
                            <div id="recipient-info" class="hidden space-y-4 pt-2 border-t border-gray-100">
                                <h3 class="text-sm font-semibold text-gray-800">Recipient Details</h3>
                                <div>
                                    <label for="recipient_name" class="block text-sm font-medium text-gray-700 mb-1.5">Recipient Name</label>
                                    <input
                                        type="text"
                                        id="recipient_name"
                                        name="recipient_name"
                                        value="{{ old('recipient_name') }}"
                                        placeholder="Full name"
                                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-colors"
                                    >
                                </div>
                                <div>
                                    <label for="recipient_email" class="block text-sm font-medium text-gray-700 mb-1.5">Recipient Email</label>
                                    <input
                                        type="email"
                                        id="recipient_email"
                                        name="recipient_email"
                                        value="{{ old('recipient_email') }}"
                                        placeholder="recipient@example.com"
                                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-colors"
                                    >
                                </div>
                                <div>
                                    <label for="gift_message" class="block text-sm font-medium text-gray-700 mb-1.5">Gift Message <span class="text-gray-400 font-normal">(Optional)</span></label>
                                    <textarea
                                        id="gift_message"
                                        name="gift_message"
                                        rows="2"
                                        placeholder="Add a personal message..."
                                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-colors resize-none"
                                    >{{ old('gift_message') }}</textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    @endif

                    @if($total > 0)
                    <!-- Payment Method -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
                        <div class="px-6 py-4 border-b border-gray-100">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-full bg-indigo-100 flex items-center justify-center mr-3">
                                    <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                                    </svg>
                                </div>
                                <h2 class="text-lg font-semibold text-gray-900">Payment Method</h2>
                            </div>
                        </div>
                        <div class="px-6 py-5 space-y-4">
                            @if (! $canAcceptCardPayments)
                                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" role="alert">
                                    Online payments are temporarily unavailable. Please contact us for assistance with your order.
                                </div>
                            @endif
                            <!-- Payment Method Tabs -->
                            <div class="flex rounded-lg border border-gray-200 p-1 bg-gray-50 gap-1">
                                @if($canAcceptIkhokhaPayments)
                                <button
                                    type="button"
                                    id="tab-ikhokha"
                                    data-method="ikhokha"
                                    onclick="selectPaymentMethod('ikhokha')"
                                    class="flex-1 flex items-center justify-center gap-2 py-2.5 px-4 rounded-md text-sm font-medium transition-all"
                                >
                                    <span class="inline-flex h-5 w-5 items-center justify-center rounded bg-emerald-600 text-[10px] font-bold text-white">iK</span>
                                    Secure payment
                                </button>
                                @endif
                                @if($canAcceptStripePayments)
                                <button
                                    type="button"
                                    id="tab-stripe"
                                    data-method="stripe"
                                    onclick="selectPaymentMethod('stripe')"
                                    class="flex-1 flex items-center justify-center gap-2 py-2.5 px-4 rounded-md text-sm font-medium transition-all"
                                >
                                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none">
                                        <rect width="24" height="24" rx="4" fill="#635BFF"/>
                                        <path d="M11.5 9.5c0-.83.67-1.5 1.5-1.5.69 0 1.27.47 1.45 1.1l1.55-.45C15.6 7.5 14.65 7 13.5 7c-1.66 0-3 1.34-3 3 0 2.21 2.55 3 3.5 3.5v-1.52c-.75-.37-2.5-1-2.5-2.48z" fill="white"/>
                                    </svg>
                                    Credit Card
                                </button>
                                @endif
                                <button
                                    type="button"
                                    id="tab-paypal"
                                    data-method="paypal"
                                    onclick="selectPaymentMethod('paypal')"
                                    class="hidden"
                                >
                                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M7.076 21.337H2.47a.641.641 0 0 1-.633-.74L4.944.901C5.026.382 5.474 0 5.998 0h7.46c2.57 0 4.578.543 5.69 1.81 1.01 1.15 1.304 2.42 1.012 4.287-.023.143-.047.288-.077.437-.983 5.05-4.349 6.797-8.647 6.797h-2.19c-.524 0-.968.382-1.05.9l-1.12 7.106zm14.146-14.42a3.35 3.35 0 0 0-.607-.541c-.013.076-.026.175-.041.254-.93 4.778-4.005 7.201-9.138 7.201h-2.19a.563.563 0 0 0-.556.479l-1.187 7.527h-.506l-.24 1.516a.56.56 0 0 0 .554.647h3.882c.46 0 .85-.334.922-.788.06-.26.76-4.852.816-5.09a.932.932 0 0 1 .923-.788h.58c3.76 0 6.705-1.528 7.565-5.946.36-1.847.174-3.388-.777-4.471z"/>
                                    </svg>
                                    PayPal
                                </button>
                            </div>
                            <input type="hidden" id="payment_method" name="payment_method" value="{{ $defaultPaymentMethod }}">

                            @if($canAcceptIkhokhaPayments)
                            <div id="ikhokha-payment" class="space-y-3">
                                <div class="rounded-lg border border-emerald-100 bg-emerald-50 p-4">
                                    <p class="font-medium text-emerald-950">Pay securely with iKhokha</p>
                                    <p class="mt-1 text-sm text-emerald-800">You will continue to iKhokha's secure payment page. Your card details are never stored by this shop.</p>
                                </div>
                            </div>
                            @endif

                            <!-- Stripe Payment Form -->
                            @if($canAcceptStripePayments)
                            <div id="stripe-payment" class="space-y-3 {{ $defaultPaymentMethod !== 'stripe' ? 'hidden' : '' }}">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Card Details</label>
                                    <div
                                        id="card-element"
                                        class="w-full px-4 py-3 border border-gray-300 rounded-lg bg-white focus-within:ring-2 focus-within:ring-indigo-500 focus-within:border-transparent transition-colors"
                                    >
                                        <!-- Stripe Elements Placeholder -->
                                    </div>
                                    <div id="card-errors" role="alert" class="mt-2 text-sm text-red-600 flex items-center gap-1 min-h-5">
                                        <svg id="card-error-icon" class="w-4 h-4 hidden flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                                        </svg>
                                        <span id="card-error-message"></span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 text-xs text-gray-500">
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                    </svg>
                                    Your payment information is encrypted and secure
                                </div>
                            </div>
                            @endif

                            <!-- PayPal Payment -->
                            <div id="paypal-payment" class="hidden">
                                <div id="paypal-button-container" class="min-h-12"></div>
                                <input type="hidden" name="paypal_payment_id" id="paypal_payment_id">
                            </div>
                        </div>
                    </div>
                    @endif

                    <!-- Submit Button -->
                    <button
                        type="submit"
                        id="submit-button"
                        @disabled($total > 0 && ! $canAcceptCardPayments)
                        class="w-full bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white font-semibold py-4 px-6 rounded-xl transition-colors duration-200 flex items-center justify-center gap-2 text-base shadow-sm disabled:opacity-60 disabled:cursor-not-allowed"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        {{ $total > 0 && ! $canAcceptCardPayments ? 'Online payments unavailable' : ($total > 0 ? 'Complete Purchase' : 'Complete Order') }}
                    </button>

                    @php($checkoutPolicies = \App\Models\Page::published()->whereIn('slug', ['terms-of-service', 'privacy-policy', 'delivery-policy', 'refund-policy'])->get())
                    <div class="text-center text-xs text-gray-500 mt-3">
                        @if($checkoutPolicies->isNotEmpty())
                            <p>Please review our store policies before ordering:</p>
                            @foreach($checkoutPolicies as $policy)
                                <a href="{{ route('cms.pages.show', $policy->slug) }}" target="_blank" rel="noopener" class="mr-3 text-indigo-600 underline">{{ $policy->title }}</a>
                            @endforeach
                        @else
                            <a href="{{ route('contact') }}" class="text-indigo-600 underline">Contact the store for delivery and order enquiries.</a>
                        @endif
                    </div>
                </form>
            </div>

            <!-- Order Summary (Right Column) -->
            <div class="lg:col-span-5 mt-8 lg:mt-0">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 sticky top-6">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <div class="flex items-center">
                            <div class="w-8 h-8 rounded-full bg-indigo-100 flex items-center justify-center mr-3">
                                <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                                </svg>
                            </div>
                            <h2 class="text-lg font-semibold text-gray-900">Order Summary</h2>
                        </div>
                    </div>

                    <!-- Cart Items -->
                    <div class="px-6 py-4 divide-y divide-gray-100 max-h-72 overflow-y-auto">
                        @foreach($cart as $productId => $item)
                            <div class="py-3 flex items-start gap-3">
                                <div class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                    </svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900 truncate">{{ $item['name'] }}</p>
                                    <p class="text-xs text-gray-500 mt-0.5">Qty: {{ $item['quantity'] }}</p>
                                </div>
                                <span class="text-sm font-semibold text-gray-900 flex-shrink-0">
                                    {{ \App\Support\StoreMoney::format($item['price'] * $item['quantity']) }}
                                </span>
                            </div>
                        @endforeach
                    </div>

                    <!-- Totals -->
                    <div class="px-6 py-4 border-t border-gray-100 space-y-3">
                        <div class="flex justify-between text-sm text-gray-600">
                            <span>Subtotal</span>
                            <span id="quote-subtotal">{{ \App\Support\StoreMoney::format($subtotal) }}</span>
                        </div>

                        @if($hasPhysicalProducts)
                            <div class="flex justify-between text-sm text-gray-600" id="shipping-cost">
                                <span>Shipping</span>
                                <span id="quote-shipping">Enter delivery details</span>
                            </div>
                        @endif

                        <div class="flex justify-between text-sm text-gray-600"><span>Discount</span><span id="quote-discount">—</span></div>
                        <div class="flex justify-between text-sm text-gray-600"><span>Tax</span><span id="quote-tax">—</span></div>
                        <p id="quote-status" role="status" aria-live="polite" class="text-sm text-gray-600">Complete your delivery details to review the total before payment.</p>
                        <div class="flex justify-between text-base font-bold text-gray-900 pt-3 border-t border-gray-200">
                            <span>Total</span>
                            <span id="total-amount">Awaiting calculation</span>
                        </div>
                    </div>

                    <!-- Trust Badges -->
                    <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 rounded-b-xl">
                        <div class="flex items-center justify-center gap-6 text-xs text-gray-500">
                            <div class="flex items-center gap-1.5">
                                <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                </svg>
                                Secure Checkout
                            </div>
                            <div class="flex items-center gap-1.5">
                                <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                                </svg>
                                Order tracking
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    let checkoutQuotedTotal = null;
    document.addEventListener('DOMContentLoaded', function() {
        // Toggle drop shipping recipient info
        const dropshipCheckbox = document.getElementById('dropship');
        const recipientInfo = document.getElementById('recipient-info');
        const dropshipSupplier = document.getElementById('dropship-supplier');

        if (dropshipCheckbox) {
            dropshipCheckbox.addEventListener('change', function() {
                const isChecked = this.checked;
                recipientInfo.classList.toggle('hidden', !isChecked);
                dropshipSupplier.classList.toggle('hidden', !isChecked);
            });
        }

        const checkoutForm = document.getElementById('checkout-form');
        const quoteStatus = document.getElementById('quote-status');
        let quoteReady = false;
        let requestVersion = 0;
        let quoteTimer;
        async function updateQuote(version) {
            if (version !== requestVersion) return;
            try {
                const response = await fetch(@json(route('checkout.quote')), {
                    method: 'POST', body: new FormData(checkoutForm), headers: { Accept: 'application/json' }
                });
                const quote = await response.json();
                if (version !== requestVersion) return;
                if (!response.ok) {
                    quoteStatus.textContent = Object.values(quote.errors || {}).flat().join(' ') || 'Unable to calculate delivery. Please check your details.';
                    return;
                }
                const format = value => quote.currency + ' ' + Number(value).toLocaleString('en-ZA', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                for (const key of ['subtotal', 'shipping', 'discount', 'tax']) {
                    const element = document.getElementById('quote-' + key);
                    if (element) element.textContent = format(quote[key]);
                }
                document.getElementById('total-amount').textContent = format(quote.total);
                checkoutQuotedTotal = quote.total;
                const purchaseButton = document.getElementById('submit-button');
                const paymentsUnavailable = quote.total > 0 && ! @json($canAcceptCardPayments);
                purchaseButton.disabled = paymentsUnavailable;
                purchaseButton.textContent = paymentsUnavailable ? 'Online payments unavailable' : (quote.total > 0 ? 'Complete Purchase' : 'Complete Order');
                quoteStatus.textContent = 'Review your total above before continuing to payment.';
                quoteReady = true;
            } catch (error) {
                if (version === requestVersion) quoteStatus.textContent = 'Unable to refresh the total. Check your connection and try again.';
            }
        }
        function scheduleQuote() {
            quoteReady = false;
            const version = ++requestVersion;
            clearTimeout(quoteTimer);
            document.getElementById('total-amount').textContent = 'Updating…';
            quoteStatus.textContent = 'Calculating your order total…';
            quoteTimer = setTimeout(() => updateQuote(version), 500);
        }
        checkoutForm.querySelectorAll('[name^="shipping_"], [name^="delivery_"], [name="dropship"]').forEach(input => {
            input.addEventListener('input', scheduleQuote);
            input.addEventListener('change', scheduleQuote);
        });
        checkoutForm.addEventListener('submit', event => {
            if (!quoteReady) {
                event.preventDefault();
                event.stopImmediatePropagation();
                quoteStatus.textContent = 'Please complete your delivery details and wait for the total before paying.';
                scheduleQuote();
            }
        }, true);
        scheduleQuote();
    });

    // Payment method tab switching
    const TAB_ACTIVE_CLASSES = ['bg-white', 'shadow-sm', 'text-gray-900', 'border', 'border-gray-200'];
    const TAB_INACTIVE_CLASSES = ['text-gray-500', 'hover:text-gray-700'];
    const paymentPanels = { ikhokha: 'ikhokha-payment', stripe: 'stripe-payment' };

    function selectPaymentMethod(method) {
        if (!document.getElementById('payment_method')) return;
        document.getElementById('payment_method').value = method;

        Object.keys(paymentPanels).forEach(function(key) {
            const panel = document.getElementById(paymentPanels[key]);
            const tab = document.getElementById('tab-' + key);
            const isActive = key === method;

            if (panel) panel.classList.toggle('hidden', !isActive);
            if (tab) {
                TAB_ACTIVE_CLASSES.forEach(cls => tab.classList.toggle(cls, isActive));
                TAB_INACTIVE_CLASSES.forEach(cls => tab.classList.toggle(cls, !isActive));
            }
        });
    }

    // Initialize payment method tabs
    selectPaymentMethod(@json($defaultPaymentMethod));
</script>

@if($total > 0 && $canAcceptStripePayments)
<script src="https://js.stripe.com/v3/"></script>
<script>
    // Stripe integration
    const stripe = Stripe('{{ config('services.stripe.key') }}');
    const elements = stripe.elements();
    const cardStyle = {
        base: {
            color: '#111827',
            fontFamily: 'ui-sans-serif, system-ui, sans-serif',
            fontSize: '15px',
            fontSmoothing: 'antialiased',
            '::placeholder': {
                color: '#9ca3af',
            },
        },
        invalid: {
            color: '#dc2626',
            iconColor: '#dc2626',
        },
    };
    const card = elements.create('card', { style: cardStyle });
    card.mount('#card-element');

    card.on('change', function(event) {
        const errorIcon = document.getElementById('card-error-icon');
        const errorMessage = document.getElementById('card-error-message');
        if (event.error) {
            errorIcon.classList.remove('hidden');
            errorMessage.textContent = event.error.message;
        } else {
            errorIcon.classList.add('hidden');
            errorMessage.textContent = '';
        }
    });

    const form = document.getElementById('checkout-form');
    const paymentMethodInput = document.getElementById('payment_method');
    const submitButton = document.getElementById('submit-button');
    let originalButtonHTML = null;

    function setButtonLoading(loading) {
        if (!submitButton) return;
        // Capture original HTML on first call (before any modification)
        if (!loading && originalButtonHTML !== null) {
            submitButton.disabled = false;
            submitButton.innerHTML = originalButtonHTML;
            return;
        }
        if (loading) {
            if (originalButtonHTML === null) {
                originalButtonHTML = submitButton.innerHTML;
            }
            submitButton.disabled = true;
            submitButton.innerHTML = '<svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Processing...';
        }
    }

    form.addEventListener('submit', async (event) => {
        if (paymentMethodInput.value === 'stripe' && checkoutQuotedTotal > 0) {
            event.preventDefault();
            setButtonLoading(true);

            const {token, error} = await stripe.createToken(card);

            if (error) {
                const errorIcon = document.getElementById('card-error-icon');
                const errorMessage = document.getElementById('card-error-message');
                errorIcon.classList.remove('hidden');
                errorMessage.textContent = error.message;
                setButtonLoading(false);
            } else {
                const hiddenInput = document.createElement('input');
                hiddenInput.setAttribute('type', 'hidden');
                hiddenInput.setAttribute('name', 'stripeToken');
                hiddenInput.setAttribute('value', token.id);
                form.appendChild(hiddenInput);
                form.submit();
            }
        }
    });

</script>
@endif
@include('checkout.delivery-windows-script')
@endpush
@endsection

