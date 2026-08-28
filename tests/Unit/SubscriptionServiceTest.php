<?php

namespace Tests\Unit;

use App\Services\SubscriptionService;
use Tests\TestCase;

class SubscriptionServiceTest extends TestCase
{
    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SubscriptionService;
    }

    public function test_update_subscription_fails_closed_when_unconfigured(): void
    {
        $result = $this->service->updateSubscription('sub_123', 'plan_456');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not configured', $result['error']);
    }

    public function test_cancel_subscription_fails_closed_when_unconfigured(): void
    {
        $result = $this->service->cancelSubscription('sub_123');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not configured', $result['error']);
    }
}
