<?php

namespace App\Services;

use App\Models\ShippingMethod;

class ShippingService
{
    public function getAvailableShippingMethods($cart = null, $address = null, ?string $postalCode = null)
    {
        // Use the same configuration rules for initial display and checkout repricing.
        $availableMethods = ShippingMethod::where('is_active', true)->get()
            ->filter(fn (ShippingMethod $method) => $method->isConfiguredForCheckout());

        if (! $cart || ! $address) {
            return $availableMethods;
        }

        // Coverage is an explicit postal-code allowlist, not a carrier quote.
        return $availableMethods->filter(function ($method) use ($cart, $address, $postalCode) {
            return (! $method->postal_codes || in_array(trim($postalCode ?? ''), $method->postal_codes, true))
                && $this->isMethodAvailable($method, $cart, $address);
        });
    }

    public function calculateShippingCost(ShippingMethod $method, $cart, $address)
    {
        // Implement logic to calculate shipping cost based on the method, cart contents, and address
        $baseRate = $method->base_rate;
        $weightRate = $this->calculateWeightRate($method, $cart);
        $distanceRate = $this->calculateDistanceRate($method, $address);

        return $baseRate + $weightRate + $distanceRate;
    }

    /** No approved address-verification provider is integrated. Null means unverified. */
    public function verifyAddress($address): ?array
    {
        // Never transmit customer addresses to a placeholder or imply they were verified.
        return null;
    }

    private function isMethodAvailable($method, $cart, $address)
    {
        // Implement logic to check if the shipping method is available for the given cart and address
        // This is a simplified example. You would need to implement more complex logic based on your specific requirements.
        $totalWeight = $this->calculateTotalWeight($cart);
        $maxWeight = $method->max_weight;

        return $totalWeight <= $maxWeight;
    }

    private function calculateWeightRate($method, $cart)
    {
        $totalWeight = $this->calculateTotalWeight($cart);

        return $totalWeight * $method->weight_rate;
    }

    private function calculateDistanceRate($method, $address)
    {
        // Implement logic to calculate distance-based rate
        // This is a placeholder. You would need to integrate with a distance calculation service.
        return 0;
    }

    private function calculateTotalWeight($cart)
    {
        return collect($cart)->sum(function (array $item): float {
            return (float) ($item['weight'] ?? 0) * (int) ($item['quantity'] ?? 0);
        });
    }

    public function calculateDropShippingCost(ShippingMethod $method, $cart, $address)
    {
        // Add a small premium for drop shipping
        $standardCost = $this->calculateShippingCost($method, $cart, $address);
        $dropShippingPremium = config('shipping.drop_shipping_premium', 2.00);

        return $standardCost + $dropShippingPremium;
    }
}
