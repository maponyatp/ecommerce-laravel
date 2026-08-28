<?php

namespace Tests\Feature;

use App\Http\Controllers\PaypalPaymentController;
use App\Services\PaymentGatewayService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class PaypalPaymentControllerTest extends TestCase
{
    public function test_create_one_time_payment_success()
    {
        $paymentGatewayServiceMock = Mockery::mock(PaymentGatewayService::class);
        $subscriptionServiceMock = Mockery::mock(SubscriptionService::class);
        $request = Request::create('/createOneTimePayment', 'POST', [
            'paymentMethodId' => 'validMethodId',
            'amount' => 100,
        ]);

        $paymentGatewayServiceMock->shouldReceive('processPaypalPayment')
            ->once()
            ->with('validMethodId', 100)
            ->andReturn(['success' => true, 'message' => 'PayPal payment successful']);

        $controller = new PaypalPaymentController($paymentGatewayServiceMock, $subscriptionServiceMock);
        $response = $controller->createOneTimePayment($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->status());
        $this->assertEquals(['success' => true, 'message' => 'PayPal payment successful'], $response->getData(true));
    }

    public function test_create_one_time_payment_failure()
    {
        $paymentGatewayServiceMock = Mockery::mock(PaymentGatewayService::class);
        $subscriptionServiceMock = Mockery::mock(SubscriptionService::class);
        $request = Request::create('/createOneTimePayment', 'POST', [
            'paymentMethodId' => 'invalidMethodId',
            'amount' => 0,
        ]);

        $paymentGatewayServiceMock->shouldReceive('processPaypalPayment')
            ->once()
            ->with('invalidMethodId', 0)
            ->andReturn(['success' => false, 'message' => 'Payment failed']);

        $controller = new PaypalPaymentController($paymentGatewayServiceMock, $subscriptionServiceMock);
        $response = $controller->createOneTimePayment($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(409, $response->status());
        $this->assertEquals(['success' => false, 'message' => 'Payment failed'], $response->getData(true));
    }

    public function test_actual_legacy_service_fails_closed_without_making_requests(): void
    {
        $service = new PaymentGatewayService;
        foreach ([$service->processPaypalPayment('pm_example', 100), $service->processPayment(100, []),
            $service->processSubscription('plan', []), $service->refundPayment('transaction', 50)] as $result) {
            $this->assertFalse($result['success']);
            $this->assertStringContainsString('unavailable', $result['error']);
        }
        $controller = new PaypalPaymentController($service, new SubscriptionService);
        $controller->createOneTimePayment(Request::create('/unused', 'POST', ['amount' => 100]));
        Http::assertNothingSent();
    }
}
