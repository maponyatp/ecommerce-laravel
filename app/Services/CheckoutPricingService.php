<?php

namespace App\Services;

use App\Models\ShippingMethod;
use App\Support\StoreMoney;
use Illuminate\Validation\ValidationException;

class CheckoutPricingService
{
    public function calculate(array $cart, array $address, ?string $couponCode = null, bool $dropship = false): array
    {
        $subtotal = round(collect($cart)->sum(fn ($item) => $item['price'] * $item['quantity']), 2);
        $physical = collect($cart)->contains(fn ($item) => ! $item['is_downloadable']);
        $discount = 0;
        if ($couponCode) {
            $coupon = app(CouponService::class)->validateAndApplyCoupon($couponCode, $subtotal);
            if (! $coupon['valid']) {
                throw ValidationException::withMessages(['coupon' => $coupon['error']]);
            }
            $discount = $coupon['discount'];
        }
        $shipping = 0;
        $tax = 0;
        $deliverySlot = null;
        if ($physical) {
            if (! array_key_exists($address['shipping_country'] ?? '', config('commerce.delivery_countries'))) {
                throw ValidationException::withMessages(['shipping_country' => 'Delivery is not available for this destination.']);
            }
            $method = ShippingMethod::find($address['shipping_method_id'] ?? null);
            $shippingService = app(ShippingService::class);
            if (! $method || ! $shippingService->getAvailableShippingMethods($cart, $address['shipping_address'], $address['shipping_postal_code'] ?? null)->contains('id', $method->id)) {
                throw ValidationException::withMessages(['shipping_method_id' => 'This delivery method is not available for your cart.']);
            }
            $deliverySlot = app(DeliverySchedulingService::class)->quote($method, filled($address['delivery_slot_id'] ?? null) ? (int) $address['delivery_slot_id'] : null);
            $shipping = $dropship
                ? $shippingService->calculateDropShippingCost($method, $cart, $address['shipping_address'])
                : $shippingService->calculateShippingCost($method, $cart, $address['shipping_address']);
            // Use explicit destination data, never infer a US address from free text.
            // Tax rules/rates remain merchant-configured; no rates are introduced here.
            $tax = app(TaxService::class)->calculateTax(max(0, $subtotal - $discount),
                $address['shipping_country'], $address['shipping_region'] ?? null,
                $address['shipping_city'], $address['shipping_postal_code']);
        }

        if (! $physical && filled($address['delivery_slot_id'] ?? null)) {
            throw ValidationException::withMessages(['delivery_slot_id' => 'Digital orders do not need a delivery window.']);
        }

        return [
            'delivery_slot' => $deliverySlot,
            'subtotal' => $subtotal, 'discount' => round($discount, 2), 'shipping' => round($shipping, 2),
            'tax' => round($tax, 2), 'total' => round(max(0, $subtotal - $discount) + $shipping + $tax, 2),
            'currency' => StoreMoney::currency(),
        ];
    }
}
