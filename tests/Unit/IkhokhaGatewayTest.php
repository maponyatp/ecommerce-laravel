<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Services\Payments\IkhokhaGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IkhokhaGatewayTest extends TestCase
{
    public static function unsafeLinks(): array
    {
        return array_map(fn ($url) => [$url], [
            'javascript:alert(1)', 'http://securepay.ikhokha.red/link',
            'https://securepay.ikhokha.red.evil.test/link', 'https://evil.test/link',
            'https://user@securepay.ikhokha.red/link', 'https://securepay.ikhokha.red:8443/link',
            'https://securepay.ikhokha.red/link#fragment', null, ['url' => 'bad'],
        ]);
    }

    #[DataProvider('unsafeLinks')]
    public function test_unsafe_payment_redirects_are_rejected(mixed $url): void
    {
        $this->assertFalse(app(IkhokhaGateway::class)->isSafePaymentUrl($url));
    }

    public function test_an_untrusted_api_endpoint_cannot_receive_credentials(): void
    {
        config(['services.ikhokha.app_id' => 'test-app', 'services.ikhokha.app_secret' => 'test-secret',
            'services.ikhokha.endpoint' => 'https://evil.test/payment']);
        $gateway = app(IkhokhaGateway::class);
        $this->assertFalse($gateway->isConfigured());
        try {
            $gateway->paymentStatus('link-1');
            $this->fail('Expected configuration rejection.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('configured gateway', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public static function invalidCreateResponses(): array
    {
        return [
            'missing id' => [['paylinkID' => null]],
            'unsafe url' => [['paylinkUrl' => 'https://evil.test/link']],
            'wrong external id' => [['externalTransactionID' => 'wrong-id']],
            'wrong response code' => [['responseCode' => '99', 'message' => 'DO-NOT-LOG-SECRET']],
        ];
    }

    #[DataProvider('invalidCreateResponses')]
    public function test_invalid_create_responses_are_not_trusted(array $overrides): void
    {
        config(['services.ikhokha.app_id' => 'test-app', 'services.ikhokha.app_secret' => 'test-secret']);
        Http::fake(['*' => Http::response(array_merge(['responseCode' => '00', 'paylinkID' => 'abc',
            'paylinkUrl' => 'https://securepay.ikhokha.red/abc'], $overrides))]);
        $order = new Order(['total_amount' => 100, 'currency' => 'ZAR']);
        $order->id = 42;
        try {
            app(IkhokhaGateway::class)->createPaymentLink($order, 'tx-1');
            $this->fail('Expected invalid response rejection.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('The payment provider returned an invalid payment link.', $exception->getMessage());
        }
    }

    public function test_status_request_does_not_follow_a_redirect(): void
    {
        config(['services.ikhokha.app_id' => 'test-app', 'services.ikhokha.app_secret' => 'test-secret']);
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://evil.test/'])]);
        try {
            app(IkhokhaGateway::class)->paymentStatus('link-1');
            $this->fail('Expected redirect rejection.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('The provider returned an invalid payment status.', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_it_creates_a_signed_payment_link_request_in_cents(): void
    {
        config()->set('services.ikhokha', [
            'app_id' => 'test-app',
            'app_secret' => 'test-secret',
            'mode' => 'live',
            'endpoint' => 'https://api.ikhokha.com/public-api/v1/api/payment',
        ]);

        Http::fake([
            '*' => Http::response([
                'responseCode' => '00',
                'paylinkUrl' => 'https://securepay.ikhokha.red/pay/abc',
                'paylinkID' => 'abc',
                'externalTransactionID' => 'tx-1',
            ]),
        ]);

        $order = new Order(['total_amount' => 123.45, 'currency' => 'ZAR']);
        $order->id = 42;

        $result = app(IkhokhaGateway::class)->createPaymentLink($order, 'tx-1');

        $this->assertSame(12345, $result['request']['amount']);
        $this->assertSame('abc', $result['response']['paylinkID']);
        foreach (['successPageUrl', 'failurePageUrl', 'cancelUrl'] as $key) {
            $this->assertTrue(URL::hasValidSignature(
                Request::create($result['request']['urls'][$key])
            ));
        }
        Http::assertSent(fn ($request) => $request->hasHeader('IK-APPID', 'test-app')
            && filled($request->header('IK-SIGN')[0] ?? null));
    }

    public function test_webhook_signature_and_app_id_are_both_required(): void
    {
        config()->set('services.ikhokha.app_id', 'test-app');
        config()->set('services.ikhokha.app_secret', 'test-secret');

        $body = '{\"paylinkID\":\"abc\",\"status\":\"SUCCESS\"}';
        $escaped = str_replace(['\\', '"', "'", "\0"], ['\\\\', '\\"', "\\'", '\\0'], '/payments/ikhokha/webhook'.$body);
        $signature = hash_hmac('sha256', str_replace('\\/', '/', $escaped), 'test-secret');
        $gateway = app(IkhokhaGateway::class);

        $this->assertTrue($gateway->verifyWebhook('/payments/ikhokha/webhook', $body, $signature, 'test-app'));
        $this->assertFalse($gateway->verifyWebhook('/payments/ikhokha/webhook', $body, $signature, 'wrong-app'));
        $this->assertFalse($gateway->verifyWebhook('/payments/ikhokha/webhook', $body, 'wrong-signature', 'test-app'));
    }
}
