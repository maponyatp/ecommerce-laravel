<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Services\StoreIntegrationService;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use RuntimeException;

class IkhokhaGateway
{
    private const ENDPOINT = 'https://api.ikhokha.com/public-api/v1/api/payment';

    public function isConfigured(): bool
    {
        return $this->configured($this->settings());
    }

    private function configured(array $settings): bool
    {
        return filled($settings['app_id'] ?? null)
            && filled($settings['app_secret'] ?? null)
            && ($settings['endpoint'] ?? self::ENDPOINT) === self::ENDPOINT;
    }

    private function settings(): array
    {
        return app(StoreIntegrationService::class)->paymentConfiguration();
    }

    /** @return array<string, mixed> */
    public function createPaymentLink(Order $order, string $externalTransactionId): array
    {
        $settings = $this->settings();
        if (! $this->configured($settings)) {
            throw new RuntimeException('iKhokha is not configured.');
        }

        if ($order->currency !== 'ZAR') {
            throw new RuntimeException('iKhokha requires an order denominated in ZAR.');
        }

        $endpoint = self::ENDPOINT;
        $payload = [
            'entityID' => (string) $settings['app_id'],
            'externalEntityID' => (string) $order->id,
            'amount' => (int) round(((float) $order->total_amount) * 100),
            'currency' => 'ZAR',
            'requesterUrl' => url('/checkout'),
            'mode' => $settings['mode'] ?? 'live',
            'description' => config('app.name').' order #'.$order->id,
            'paymentReference' => 'ORDER-'.$order->id,
            'externalTransactionID' => $externalTransactionId,
            'urls' => [
                'callbackUrl' => route('payments.ikhokha.webhook'),
                'successPageUrl' => URL::temporarySignedRoute('payments.ikhokha.return', now()->addDays(7), ['order' => $order]),
                'failurePageUrl' => URL::temporarySignedRoute('payments.ikhokha.return', now()->addDays(7), ['order' => $order, 'result' => 'failed']),
                'cancelUrl' => URL::temporarySignedRoute('payments.ikhokha.return', now()->addDays(7), ['order' => $order, 'result' => 'cancelled']),
            ],
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = $this->sign(parse_url($endpoint, PHP_URL_PATH), $body, $settings['app_secret']);

        try {
            $response = Http::acceptJson()->withoutRedirecting()
                ->withHeaders([
                    'IK-APPID' => trim((string) $settings['app_id']),
                    'IK-SIGN' => $signature,
                ])
                ->withBody($body, 'application/json')
                ->connectTimeout(5)->timeout(20)
                ->post($endpoint)
                ->throw();
        } catch (HttpClientException $exception) {
            // Do not retain provider bodies, request signatures or secrets in logs.
            throw new RuntimeException('The payment provider could not confirm creation of the payment link.');
        }

        $data = $response->json();
        if (! $response->successful() || ! is_array($data) || ($data['responseCode'] ?? null) !== '00'
            || ! $this->validReference($data['paylinkID'] ?? null)
            || ! $this->isSafePaymentUrl($data['paylinkUrl'] ?? null)
            || (isset($data['externalTransactionID']) && $data['externalTransactionID'] !== $externalTransactionId)) {
            throw new RuntimeException('The payment provider returned an invalid payment link.');
        }

        return ['request' => $payload, 'response' => $data];
    }

    public function isSafePaymentUrl(mixed $url): bool
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);

        return ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) === 'securepay.ikhokha.red'
            && ! isset($parts['user'], $parts['pass']) && ! isset($parts['user'])
            && ! isset($parts['port']) && ! isset($parts['fragment']);
    }

    public function validReference(mixed $reference): bool
    {
        return is_string($reference) && preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $reference) === 1;
    }

    /** Authenticated, read-only lookup. Only documented PAID status can settle an order. */
    public function paymentStatus(string $reference): array
    {
        $settings = $this->settings();
        if (! $this->configured($settings) || ! $this->validReference($reference)) {
            throw new RuntimeException('A configured gateway and valid payment reference are required.');
        }
        $path = '/public-api/v1/api/getStatus/'.$reference;
        try {
            $response = Http::acceptJson()->withoutRedirecting()->withHeaders([
                'IK-APPID' => trim((string) $settings['app_id']),
                'IK-SIGN' => $this->sign($path, '', $settings['app_secret']),
            ])->connectTimeout(5)->timeout(15)->get('https://api.ikhokha.com'.$path)->throw();
        } catch (HttpClientException $exception) {
            throw new RuntimeException('Payment status could not be verified with the provider.');
        }
        $data = $response->json();
        if (! $response->successful() || ! is_array($data) || ($data['paylinkID'] ?? null) !== $reference
            || ! is_string($data['status'] ?? null) || strlen($data['status']) > 64
            || ! is_int($data['amount'] ?? null) || $data['amount'] <= 0
            || (isset($data['currency']) && $data['currency'] !== 'ZAR')) {
            throw new RuntimeException('The provider returned an invalid payment status.');
        }

        return array_intersect_key($data, array_flip(['paylinkID', 'status', 'amount']));
    }

    public function verifyWebhook(string $path, string $body, ?string $signature, ?string $appId): bool
    {
        $settings = $this->settings();
        if (! $this->configured($settings) || blank($signature) || blank($appId)) {
            return false;
        }

        if (! hash_equals(trim((string) $settings['app_id']), trim($appId))) {
            return false;
        }

        return hash_equals($this->sign($path, $body, $settings['app_secret']), trim($signature));
    }

    private function sign(string $path, string $body, string $secret): string
    {
        $payload = str_replace(
            ['\\', '"', "'", "\0"],
            ['\\\\', '\\"', "\\'", '\\0'],
            $path.$body,
        );

        return hash_hmac('sha256', str_replace('\\/', '/', $payload), trim($secret));
    }
}
