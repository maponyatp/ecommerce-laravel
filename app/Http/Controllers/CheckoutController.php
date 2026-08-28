<?php

namespace App\Http\Controllers;

use App\Factories\PaymentGatewayFactory;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\CartService;
use App\Services\CheckoutPricingService;
use App\Services\CouponService;
use App\Services\DeliverySchedulingService;
use App\Services\DigitalFulfillmentService;
use App\Services\InvoiceDocumentService;
use App\Services\OrderPaymentService;
use App\Services\Payments\IkhokhaGateway;
use App\Services\ShippingService;
use App\Services\StockReservationService;
use App\Services\TaxService;
use App\Settings\GeneralSettings;
use App\Support\StoreMoney;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    protected $shippingService;

    protected $taxService;

    protected $couponService;

    public function __construct(ShippingService $shippingService, TaxService $taxService, CouponService $couponService)
    {
        $this->shippingService = $shippingService;
        $this->taxService = $taxService;
        $this->couponService = $couponService;
    }

    public function initiateCheckout(Request $request)
    {
        Session::put('checkout_identity', Session::get('checkout_identity', (string) Str::uuid()));
        $isGuest = Session::get('is_guest', false);
        $cart = app(CartService::class)->current();

        if (empty($cart)) {
            return redirect()->route('products.index')
                ->with('error', 'Your cart is empty');
        }

        $lastKey = Session::get('checkout_last_key');
        if ($lastKey && Session::get('checkout_last_cart') === hash('sha256', serialize($cart))) {
            $previous = Order::where('checkout_key', $lastKey)->first();
            if ($previous && ($previous->status === 'payment_review'
                || ($previous->stock_reservation_status === 'held' && $previous->stock_reserved_until?->isFuture()))) {
                return $this->resumeCheckout($previous);
            }
            Session::put('checkout_nonce', (string) Str::uuid());
            Session::forget('checkout_last_key');
        }
        $cart = $this->hydrateCart($cart);
        $shippingMethods = $this->shippingService->getAvailableShippingMethods();

        // Calculate subtotal and whether physical products exist
        $subtotal = collect($cart)->sum(function ($item) {
            return $item['price'] * $item['quantity'];
        });

        $hasPhysicalProducts = $this->hasPhysicalProducts($cart);

        $canAcceptIkhokhaPayments = app(IkhokhaGateway::class)->isConfigured();
        $canAcceptStripePayments = filled(config('services.stripe.key')) && filled(config('services.stripe.secret'));

        return view('checkout.checkout', [
            'cart' => $cart,
            'shippingMethods' => $shippingMethods,
            'deliveryWindows' => $shippingMethods->filter->requires_delivery_slot
                ->mapWithKeys(fn ($method) => [$method->id => app(DeliverySchedulingService::class)->choices($method->id)]),
            'isGuest' => $isGuest,
            'subtotal' => $subtotal,
            'total' => $subtotal,
            'hasPhysicalProducts' => $hasPhysicalProducts,
            'canAcceptCardPayments' => $canAcceptIkhokhaPayments || $canAcceptStripePayments,
            'canAcceptIkhokhaPayments' => $canAcceptIkhokhaPayments,
            'canAcceptStripePayments' => $canAcceptStripePayments,
            'defaultPaymentMethod' => $canAcceptIkhokhaPayments ? 'ikhokha' : 'stripe',
        ]);
    }

    public function processCheckout(Request $request)
    {
        $cart = app(CartService::class)->current();

        if (empty($cart)) {
            return redirect()->route('products.index')
                ->with('error', 'Your cart is empty');
        }

        // Stable across legitimate session-ID rotation; never accepted from form input.
        Session::put('checkout_identity', Session::get('checkout_identity', Session::getId()));
        $checkoutKey = hash('sha256', serialize([
            Session::get('checkout_identity'), Session::get('checkout_nonce', 'initial'), $cart,
            $request->only(['email', 'shipping_address', 'shipping_method_id', 'delivery_contact_name',
                'delivery_phone', 'shipping_country', 'shipping_city', 'shipping_region', 'shipping_postal_code',
                'delivery_slot_id', 'payment_method', 'dropship', 'recipient_name', 'recipient_email', 'gift_message',
                'billing_name', 'billing_address', 'billing_is_vat_vendor', 'billing_vat_number']),
            Session::get('coupon.code'),
        ]));
        if ($previous = Order::where('checkout_key', $checkoutKey)->first()) {
            return $this->resumeCheckout($previous);
        }
        $cart = $this->hydrateCart($cart);
        $hasPhysicalProducts = $this->hasPhysicalProducts($cart);
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
            'billing_name' => [Rule::requiredIf(app(GeneralSettings::class)->invoice_vat_status === 'registered'), 'required_with:billing_address,billing_vat_number', 'nullable', 'string', 'max:255'],
            'billing_address' => [Rule::requiredIf(app(GeneralSettings::class)->invoice_vat_status === 'registered'), 'required_with:billing_name,billing_vat_number', 'nullable', 'string', 'max:1000'],
            'billing_is_vat_vendor' => ['sometimes', 'boolean'],
            'billing_vat_number' => ['required_if:billing_is_vat_vendor,1', 'nullable', 'regex:/^\d{10}$/'],
            ...$this->deliveryRules($hasPhysicalProducts),
            'payment_method' => 'nullable|in:ikhokha,stripe',
            'stripeToken' => 'nullable|string|max:255',
            ...$this->supplierOptionsRules(),
            'recipient_name' => 'nullable|string|max:255',
            'recipient_email' => 'nullable|email|max:255',
            'gift_message' => 'nullable|string|max:2000',
        ], $this->supplierOptionsMessages());

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $couponCode = Session::get('coupon.code');
        $totals = $this->priceCart($request, $cart);
        $shippingCost = $totals['shipping'];
        $discountAmount = $totals['discount'];
        $taxAmount = $totals['tax'];
        $totalAmount = $totals['total'];
        $quoted = Session::get('checkout_quote');
        if ($quoted && ! hash_equals($quoted, $this->quoteFingerprint($request, $cart, $totals))) {
            throw ValidationException::withMessages(['quote' => 'Your order details or prices changed. Please review the refreshed total before paying.']);
        }

        $ikhokha = app(IkhokhaGateway::class);
        $stripeConfigured = filled(config('services.stripe.key')) && filled(config('services.stripe.secret'));
        if ($totalAmount > 0 && ! $ikhokha->isConfigured() && ! $stripeConfigured) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Online payments are temporarily unavailable. Please contact us for assistance.');
        }
        if ($totalAmount > 0 && ! match ($request->payment_method) {
            'ikhokha' => $ikhokha->isConfigured(),
            'stripe' => $stripeConfigured && filled($request->input('stripeToken')),
            default => false,
        }) {
            return back()->withInput()->withErrors(['payment_method' => 'Choose an available payment method and provide its payment details.']);
        }

        // The unique checkout key is also a database backstop to the session lock.
        try {
            $order = DB::transaction(function () use ($request, $cart, $totalAmount, $shippingCost, $taxAmount, $discountAmount, $couponCode, $hasPhysicalProducts, $checkoutKey, $totals) {
                $order = Order::create([
                    'checkout_key' => $checkoutKey,
                    'currency' => StoreMoney::currency(),
                    'user_id' => $request->user()?->id,
                    'customer_id' => null,
                    'customer_email' => $request->email,
                    'billing_details' => $request->filled('billing_name') ? [
                        'name' => $request->billing_name, 'address' => $request->billing_address,
                        'is_vat_vendor' => $request->boolean('billing_is_vat_vendor') || $request->filled('billing_vat_number'),
                        'vat_number' => $request->billing_vat_number,
                    ] : null,
                    'invoice_context' => app(InvoiceDocumentService::class)->context(),
                    'order_date' => now()->toDateTimeString(),
                    'shipping_address' => $hasPhysicalProducts ? implode("\n", array_filter([
                        $request->shipping_address, $request->shipping_city, $request->shipping_region,
                        $request->shipping_postal_code, config('commerce.delivery_countries.'.$request->shipping_country),
                    ])) : null,
                    'delivery_contact_name' => $hasPhysicalProducts ? $request->delivery_contact_name : null,
                    'delivery_phone' => $hasPhysicalProducts ? $request->delivery_phone : null,
                    'shipping_country' => $hasPhysicalProducts ? $request->shipping_country : null,
                    'shipping_city' => $hasPhysicalProducts ? $request->shipping_city : null,
                    'shipping_region' => $hasPhysicalProducts ? $request->shipping_region : null,
                    'shipping_postal_code' => $hasPhysicalProducts ? $request->shipping_postal_code : null,
                    'shipping_method_id' => $hasPhysicalProducts ? $request->shipping_method_id : null,
                    'payment_method' => $totalAmount > 0 ? $request->payment_method : 'free',
                    'total_amount' => $totalAmount,
                    'shipping_cost' => $shippingCost,
                    'tax_amount' => $taxAmount,
                    'discount_amount' => $discountAmount,
                    'coupon_code' => $couponCode,
                    'status' => 'pending',
                    'payment_status' => 'pending',
                    'shipping_status' => $this->hasPhysicalProducts($cart) ? 'unfulfilled' : 'not_required',
                    'is_dropshipped' => false,
                    'recipient_name' => $hasPhysicalProducts ? $request->delivery_contact_name : $request->recipient_name,
                    'recipient_email' => $request->recipient_email,
                    'gift_message' => $request->gift_message,
                ]);

                // Create order items
                foreach ($cart as $productId => $item) {
                    $order->items()->create([
                        'product_name_snapshot' => $item['name'],
                        'is_downloadable_snapshot' => $item['is_downloadable'],
                        'product_id' => app(CartService::class)->reference($productId)[0],
                        'product_variant_id' => $item['product_variant_id'] ?? null,
                        'sku_snapshot' => $item['sku_snapshot'] ?? null,
                        'options_snapshot' => $item['options_snapshot'] ?? null,
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
                    ]);
                }

                app(StockReservationService::class)->reserve($order);
                app(DeliverySchedulingService::class)->reserve($order, $request->filled('delivery_slot_id') ? $request->integer('delivery_slot_id') : null, $totals['delivery_slot']);

                return $order;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if ($previous = Order::where('checkout_key', $checkoutKey)->first()) {
                return $this->resumeCheckout($previous);
            }
            throw $exception;
        }
        Session::put('checkout_last_key', $checkoutKey);
        Session::put('checkout_last_cart', hash('sha256', serialize(Session::get('cart', []))));

        if ($totalAmount > 0 && $request->payment_method === 'ikhokha') {
            if (! $ikhokha->isConfigured()) {
                DB::transaction(function () use ($order) {
                    app(StockReservationService::class)->release($order);
                    $order->update(['status' => 'payment_failed', 'payment_status' => 'failed']);
                });

                return redirect()->back()->withInput()->with('error', 'iKhokha payments are temporarily unavailable.');
            }

            $externalId = 'ORDER-'.$order->id.'-'.Str::uuid();
            $transaction = PaymentTransaction::create([
                'order_id' => $order->id,
                'gateway' => 'ikhokha',
                'external_transaction_id' => $externalId,
                'amount' => $order->total_amount,
                'currency' => $order->currency,
                'status' => 'pending',
            ]);

            try {
                $payment = $ikhokha->createPaymentLink($order, $externalId);
                $transaction->update([
                    'gateway_reference' => $payment['response']['paylinkID'],
                    'response_code' => $payment['response']['responseCode'],
                    'request_payload' => $payment['request'],
                    'response_payload' => $payment['response'],
                ]);
                Order::whereKey($order->id)->where('payment_status', 'pending')->update(['status' => 'awaiting_payment']);
                Session::put('pending_checkout', [
                    'order_id' => $order->id,
                    'cart_hash' => hash('sha256', serialize(Session::get('cart', []))),
                ]);

                return redirect()->away($payment['response']['paylinkUrl']);
            } catch (\Throwable $exception) {
                Log::error('Unable to create iKhokha payment link.', [
                    'order_id' => $order->id,
                    'exception_type' => get_class($exception),
                ]);
                // A timeout may have created a live link; never pretend it proves failure.
                Order::whereKey($order->id)->where('payment_status', 'pending')->update(['status' => 'payment_review']);

                return redirect()->to($this->confirmationUrl($order))->with('error', 'Payment needs verification. Your cart is kept; contact the store before paying again.');
            }
        }

        $payments = app(OrderPaymentService::class);
        if ($totalAmount > 0) {
            $transaction = PaymentTransaction::create([
                'order_id' => $order->id, 'gateway' => 'stripe',
                'external_transaction_id' => 'STRIPE-ORDER-'.$order->id,
                'amount' => $order->total_amount, 'currency' => $order->currency, 'status' => 'pending',
            ]);
            $paymentResult = $this->processStripePayment($order, $request->stripeToken);
            if (! $paymentResult['success']) {
                if ($paymentResult['definitive_failure'] ?? false) {
                    $payments->markFailed($transaction);
                } else {
                    $order->update(['status' => 'payment_review']);
                }

                return redirect()->to($this->confirmationUrl($order))
                    ->with('error', 'Payment could not be confirmed. Please check this order or contact the store before trying again.');
            }
            $transaction->update(['gateway_reference' => $paymentResult['transaction_id'] ?? null]);
            $order = $payments->markPaid($transaction, ['responseCode' => '00']);
        } else {
            $order = $payments->settleFree($order);
        }

        // Clear cart and coupon
        Session::forget('cart');
        Session::forget('coupon');
        Session::forget(['checkout_quote', 'checkout_last_key', 'checkout_last_cart']);
        Session::put('checkout_nonce', (string) Str::uuid());

        return redirect()->to($this->confirmationUrl($order))
            ->with('success', 'Order placed successfully!');
    }

    public function showConfirmation(Order $order)
    {
        app(DigitalFulfillmentService::class)->issue($order);
        $pending = Session::get('pending_checkout', []);
        if ($order->payment_status === 'paid' && ($pending['order_id'] ?? null) === $order->id) {
            if (hash_equals($pending['cart_hash'] ?? '', hash('sha256', serialize(Session::get('cart', []))))) {
                Session::forget(['cart', 'coupon', 'checkout_quote', 'checkout_last_key', 'checkout_last_cart']);
                Session::put('checkout_nonce', (string) Str::uuid());
            }
            Session::forget('pending_checkout');
        }
        $order->load(['items.product', 'shippingMethod', 'invoice']);

        return response()->view('checkout.confirmation', [
            'order' => $order,
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function resumeCheckout(Order $order)
    {
        if ($order->payment_method === 'free' && ! $order->payment_processed_at) {
            $order = app(OrderPaymentService::class)->settleFree($order);
        }
        if ($order->payment_status === 'pending' && $order->stock_reserved_until?->isFuture()
            && $order->stock_reservation_status === 'held') {
            $url = $order->paymentTransactions()->where('gateway', 'ikhokha')->where('status', 'pending')
                ->latest('id')->first()?->response_payload['paylinkUrl'] ?? null;
            if (app(IkhokhaGateway::class)->isSafePaymentUrl($url)) {
                return redirect()->away($url);
            }
        }

        return redirect()->to($this->confirmationUrl($order))
            ->with('warning', 'This checkout already has an order. Check its payment status before starting another payment.');
    }

    private function confirmationUrl(Order $order): string
    {
        return URL::temporarySignedRoute(
            'checkout.confirmation',
            now()->addDays(7),
            ['order' => $order->id],
        );
    }

    public function guestCheckout(Request $request)
    {
        $cart = app(CartService::class)->current();

        // Store cart in guest session
        Session::put('guest_cart', $cart);
        Session::put('is_guest', true);

        return redirect()->route('checkout.initiate');
    }

    protected function processPayment($order, $paymentMethod)
    {
        $paymentGateway = PaymentGatewayFactory::create($paymentMethod);

        return $paymentGateway->processPayment($order->total_amount, [
            'order_id' => $order->id,
            'customer_email' => $order->customer_email,
        ]);
    }

    private function hasPhysicalProducts($cart)
    {
        foreach ($cart as $item) {
            if (! $item['is_downloadable']) {
                return true;
            }
        }

        return false;
    }

    private function hydrateCart(array $cart): array
    {
        return app(CartService::class)->hydrate($cart);
    }

    private function deliveryRules(bool $physical): array
    {
        return [
            'shipping_address' => [Rule::requiredIf($physical), 'nullable', 'string', 'max:2000'],
            'shipping_method_id' => [Rule::requiredIf($physical), 'nullable', 'integer', 'exists:shipping_methods,id'],
            'delivery_contact_name' => [Rule::requiredIf($physical), 'nullable', 'string', 'max:120'],
            'delivery_slot_id' => ['nullable', 'integer', 'min:1'],
            'delivery_phone' => [Rule::requiredIf($physical), 'nullable', 'string', 'max:32', 'regex:/^\+?[0-9 ()-]{7,32}$/'],
            'shipping_country' => [Rule::requiredIf($physical), 'nullable', Rule::in(array_keys(config('commerce.delivery_countries')))],
            'shipping_city' => [Rule::requiredIf($physical), 'nullable', 'string', 'max:120'],
            'shipping_region' => ['nullable', 'string', 'max:120'],
            'shipping_postal_code' => [Rule::requiredIf($physical), 'nullable', 'string', 'regex:/^[0-9]{4}$/D'],
        ];
    }

    private function priceCart(Request $request, array $cart): array
    {
        try {
            return app(CheckoutPricingService::class)->calculate(
                $cart, $request->all(), Session::get('coupon.code'));
        } catch (ValidationException $exception) {
            if (isset($exception->errors()['coupon'])) {
                Session::forget('coupon');
            }
            throw $exception;
        }
    }

    public function quote(Request $request)
    {
        $cart = $this->hydrateCart(app(CartService::class)->current());
        abort_if(empty($cart), 422, 'Your cart is empty.');
        $request->validate($this->deliveryRules($this->hasPhysicalProducts($cart)) + $this->supplierOptionsRules(), $this->supplierOptionsMessages());

        $totals = $this->priceCart($request, $cart);
        Session::put('checkout_quote', $this->quoteFingerprint($request, $cart, $totals));

        return response()->json($totals)->header('Cache-Control', 'private, no-store');
    }

    private function quoteFingerprint(Request $request, array $cart, array $totals): string
    {
        return hash('sha256', json_encode([$cart, $totals,
            $request->only(array_keys($this->deliveryRules($this->hasPhysicalProducts($cart)))),
            $request->boolean('dropship')], JSON_THROW_ON_ERROR));
    }

    private function supplierOptionsRules(): array
    {
        // Preserve an explicitly disabled legacy flag, but never initiate supplier purchasing.
        return ['dropship' => 'nullable|in:0', 'supplier_id' => 'prohibited'];
    }

    private function supplierOptionsMessages(): array
    {
        return ['dropship.in' => CheckoutPricingService::SUPPLIER_UNAVAILABLE,
            'supplier_id.prohibited' => CheckoutPricingService::SUPPLIER_UNAVAILABLE];
    }

    protected function processStripePayment($order, $stripeToken)
    {
        $paymentGateway = PaymentGatewayFactory::create('stripe');

        return $paymentGateway->processPayment($order->total_amount, [
            'order_id' => $order->id,
            'customer_email' => $order->customer_email,
            'token' => $stripeToken,
            'currency' => $order->currency,
        ]);
    }

    protected function processPayPalPayment($order, $paypalPaymentId)
    {
        $paymentGateway = PaymentGatewayFactory::create('paypal');

        return $paymentGateway->processPayment($order->total_amount, [
            'order_id' => $order->id,
            'customer_email' => $order->customer_email,
            'payment_id' => $paypalPaymentId,
        ]);
    }
}
