<?php

namespace App\Services\PaymentGateways;

use App\Interfaces\PaymentGatewayInterface;
use App\Support\StoreMoney;
use Exception;
use Illuminate\Support\Facades\Config;
use Stripe\Exception\CardException;
use Stripe\StripeClient;

class StripeGateway implements PaymentGatewayInterface
{
    private $stripeClient;

    public function __construct()
    {
        $this->stripeClient = new StripeClient(Config::get('services.stripe.secret'));
    }

    public function processPayment(float $amount, array $paymentDetails): array
    {
        try {
            $charge = $this->stripeClient->charges->create([
                'amount' => (int) round($amount * 100),
                'currency' => strtolower($paymentDetails['currency'] ?? StoreMoney::currency()),
                'source' => $paymentDetails['token'],
                'description' => 'Payment transaction',
            ], isset($paymentDetails['order_id']) ? ['idempotency_key' => 'store-order-'.$paymentDetails['order_id']] : []);

            if ($charge->status === 'succeeded') {
                return ['success' => true, 'transaction_id' => $charge->id];
            }

            return ['success' => false, 'error' => 'Payment failed', 'definitive_failure' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Payment could not be confirmed. Please check your order before trying again.',
                'definitive_failure' => $e instanceof CardException];
        }
    }

    public function processSubscription(string $planId, array $subscriptionDetails): array
    {
        return [
            'success' => false,
            'error' => 'Stripe subscriptions are not configured for this store.',
        ];
    }

    public function refundPayment(string $transactionId, float $amount): array
    {
        try {
            $refund = $this->stripeClient->refunds->create([
                'charge' => $transactionId,
                'amount' => $amount * 100, // Stripe expects amount in cents
            ]);

            if ($refund->status === 'succeeded') {
                return ['success' => true, 'refund_id' => $refund->id];
            }

            return ['success' => false, 'error' => 'Refund failed'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
