<?php

namespace App\Services\PaymentGateways;

use App\Interfaces\PaymentGatewayInterface;
use Exception;
use Illuminate\Support\Facades\Config;
use PayPal\Api\Amount;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\RedirectUrls;
use PayPal\Api\RefundRequest;
use PayPal\Api\Sale;
use PayPal\Api\Transaction;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Rest\ApiContext;

class PayPalGateway implements PaymentGatewayInterface
{
    private $apiContext;

    public function __construct()
    {
        $this->apiContext = new ApiContext(
            new OAuthTokenCredential(
                Config::get('services.paypal.client_id'),
                Config::get('services.paypal.secret')
            )
        );
        $this->apiContext->setConfig(Config::get('services.paypal.settings'));
    }

    public function processPayment(float $amount, array $paymentDetails): array
    {
        $payer = new Payer;
        $payer->setPaymentMethod('paypal');

        $amountDetails = new Amount;
        $amountDetails->setTotal($amount)
            ->setCurrency('USD');

        $transaction = new Transaction;
        $transaction->setAmount($amountDetails)
            ->setDescription('Payment transaction');

        $redirectUrls = new RedirectUrls;
        $redirectUrls->setReturnUrl(url('/payment/success'))
            ->setCancelUrl(url('/payment/cancel'));

        $payment = new Payment;
        $payment->setIntent('sale')
            ->setPayer($payer)
            ->setTransactions([$transaction])
            ->setRedirectUrls($redirectUrls);

        try {
            $payment->create($this->apiContext);

            return ['success' => true, 'payment_id' => $payment->getId(), 'approval_url' => $payment->getApprovalLink()];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function processSubscription(string $planId, array $subscriptionDetails): array
    {
        return [
            'success' => false,
            'error' => 'PayPal subscriptions are not configured for this store.',
        ];
    }

    public function refundPayment(string $transactionId, float $amount): array
    {
        try {
            // Retrieve the sale transaction
            $sale = Sale::get($transactionId, $this->apiContext);

            // Create refund object
            $refund = new RefundRequest;
            $refundAmount = new Amount;
            $refundAmount->setCurrency('USD')
                ->setTotal($amount);
            $refund->setAmount($refundAmount);

            // Execute the refund
            $refundedSale = $sale->refundSale($refund, $this->apiContext);

            if ($refundedSale->getState() === 'completed') {
                return [
                    'success' => true,
                    'refund_id' => $refundedSale->getId(),
                    'status' => $refundedSale->getState(),
                ];
            }

            return [
                'success' => false,
                'error' => 'Refund not completed',
                'status' => $refundedSale->getState(),
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
