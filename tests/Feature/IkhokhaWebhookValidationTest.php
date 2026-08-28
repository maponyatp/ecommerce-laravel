<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IkhokhaWebhookValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_malformed_json_is_controlled_without_database_mutation(): void
    {
        foreach (['{bad-json', 'null', '[]', '"text"'] as $body) {
            $this->call('POST', '/payments/ikhokha/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)
                ->assertStatus(400);
        }
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_oversized_json_is_rejected(): void
    {
        $this->postJson('/payments/ikhokha/webhook', ['text' => str_repeat('x', 65536)])->assertStatus(413);
    }

    public function test_valid_signature_does_not_allow_array_shaped_identifiers(): void
    {
        config(['services.ikhokha.app_id' => 'test-app', 'services.ikhokha.app_secret' => 'test-secret']);
        $payload = ['externalTransactionID' => ['unexpected'], 'paylinkID' => 'abc', 'status' => 'SUCCESS', 'responseCode' => '00'];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $escaped = str_replace(['\\', '"', "'", "\0"], ['\\\\', '\\"', "\\'", '\\0'], '/payments/ikhokha/webhook'.$body);
        $signature = hash_hmac('sha256', str_replace('\\/', '/', $escaped), 'test-secret');
        $this->postJson('/payments/ikhokha/webhook', $payload, ['ik-appid' => 'test-app', 'ik-sign' => $signature])
            ->assertStatus(422);
        $this->assertDatabaseCount('payment_transactions', 0);
    }
}
