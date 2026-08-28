<?php

namespace App\Services;

/** Legacy compatibility only. Arbitrary charges must not bypass order checkout. */
class PaymentGatewayService
{
    private function unavailable(): array
    {
        return ['success' => false, 'error' => 'This legacy payment flow is unavailable. Use the supported order checkout workflow.'];
    }

    public function processPaypalPayment($paymentMethodId, $amount): array
    {
        return $this->unavailable();
    }

    public function processPayment(float $amount, array $paymentDetails): array
    {
        return $this->unavailable();
    }

    public function processSubscription(string $planId, array $subscriptionDetails): array
    {
        return $this->unavailable();
    }

    public function refundPayment(string $transactionId, float $amount): array
    {
        return $this->unavailable();
    }
}
