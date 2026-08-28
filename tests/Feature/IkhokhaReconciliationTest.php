<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\PaymentTransactions\Pages\ListPaymentTransactions;
use App\Models\Order;
use App\Models\PaymentStatusCheck;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderPaymentService;
use App\Services\Payments\IkhokhaReconciliationService;
use App\Services\StockReservationService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IkhokhaReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['services.ikhokha.app_id' => 'test-app', 'services.ikhokha.app_secret' => 'test-secret']);
    }

    private function staff(bool $edit = true): User
    {
        $actor = User::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $actor->assignRole(Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']));
        foreach (['view_any_order', 'view_order', ...($edit ? ['update_order'] : [])] as $name) {
            $actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $actor;
    }

    private function payment(): PaymentTransaction
    {
        return DB::transaction(function () {
            $product = Product::factory()->create(['price' => 100, 'inventory_count' => 3, 'is_downloadable' => false]);
            $order = Order::create(['customer_email' => 'buyer@example.test', 'order_date' => now(),
                'currency' => 'ZAR', 'total_amount' => 100, 'payment_method' => 'ikhokha',
                'payment_status' => 'pending', 'status' => 'awaiting_payment', 'shipping_status' => 'unfulfilled']);
            $order->items()->create(['product_id' => $product->id, 'quantity' => 1, 'price' => 100]);
            app(StockReservationService::class)->reserve($order);

            return PaymentTransaction::create(['order_id' => $order->id, 'gateway' => 'ikhokha',
                'external_transaction_id' => 'ORDER-'.$order->id, 'gateway_reference' => 'link-'.$order->id,
                'amount' => 100, 'currency' => 'ZAR', 'status' => 'pending']);
        });
    }

    private function provider(PaymentTransaction $payment, array $overrides = []): void
    {
        Http::fake(['https://api.ikhokha.com/*' => Http::response(array_merge([
            'paylinkID' => $payment->gateway_reference, 'status' => 'PAID', 'amount' => 10000,
        ], $overrides))]);
    }

    public function test_missed_callback_is_settled_once_with_invoice_receipt_and_staff_audit(): void
    {
        $actor = $this->staff();
        $payment = $this->payment();
        $this->provider($payment);
        $service = app(IkhokhaReconciliationService::class);
        $this->assertSame('paid', $service->check($payment, $actor));
        $this->assertSame('already_paid', $service->check($payment, $actor));
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('paid', $payment->order->fresh()->payment_status);
        $this->assertSame(2, Product::first()->inventory_count);
        $this->assertDatabaseCount('inventory_logs', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('payment_status_checks', 1);
        $this->assertSame($actor->id, PaymentStatusCheck::first()->checked_by);
        Notification::assertCount(1);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://api.ikhokha.com/public-api/v1/api/getStatus/'.$payment->gateway_reference
            && $request->hasHeader('IK-APPID', 'test-app')
            && $request->hasHeader('IK-SIGN', hash_hmac('sha256', '/public-api/v1/api/getStatus/'.$payment->gateway_reference, 'test-secret')));
    }

    public static function uncertainResponses(): array
    {
        return [
            'pending' => [['status' => 'PENDING'], 'unconfirmed'],
            'failed is not inferred terminal' => [['status' => 'FAILED'], 'unconfirmed'],
            'unknown' => [['status' => 'NEW_STATUS'], 'unconfirmed'],
            'underpaid' => [['amount' => 9900], 'mismatch'],
            'overpaid' => [['amount' => 10100], 'mismatch'],
            'wrong id' => [['paylinkID' => 'other-link'], 'provider_unavailable'],
            'wrong currency' => [['currency' => 'USD'], 'provider_unavailable'],
            'string amount' => [['amount' => '10000'], 'provider_unavailable'],
            'array status' => [['status' => ['PAID']], 'provider_unavailable'],
        ];
    }

    #[DataProvider('uncertainResponses')]
    public function test_uncertain_or_mismatched_results_do_not_move_money_or_inventory(array $payload, string $outcome): void
    {
        $actor = $this->staff();
        $payment = $this->payment();
        $this->provider($payment, $payload);
        $this->assertSame($outcome, app(IkhokhaReconciliationService::class)->check($payment, $actor));
        $this->assertSame('pending', $payment->order->fresh()->payment_status);
        $this->assertSame('held', $payment->order->fresh()->stock_reservation_status);
        $this->assertSame(3, Product::first()->inventory_count);
        $this->assertSame($outcome, PaymentStatusCheck::first()->outcome);
        $this->assertDatabaseCount('invoices', 0);
        Notification::assertNothingSent();
    }

    public function test_network_failure_is_audited_without_provider_secrets_or_state_changes(): void
    {
        $actor = $this->staff();
        $payment = $this->payment();
        Http::fake(['*' => Http::response(['secret' => 'never-persist'], 500)]);
        $this->assertSame('provider_unavailable', app(IkhokhaReconciliationService::class)->check($payment, $actor));
        $this->assertStringNotContainsString('never-persist', PaymentStatusCheck::first()->toJson());
        $this->assertSame('pending', $payment->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_missing_reference_and_credentials_do_not_call_provider(): void
    {
        $actor = $this->staff();
        $payment = $this->payment();
        foreach (['reference', 'credentials'] as $case) {
            if ($case === 'reference') {
                $payment->update(['gateway_reference' => null]);
            } else {
                $payment->update(['gateway_reference' => 'link-1']);
                config(['services.ikhokha.app_secret' => '']);
            }
            try {
                app(IkhokhaReconciliationService::class)->check($payment, $actor);
                $this->fail('Expected configuration validation.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('payment', $exception->errors());
            }
        }
        Http::assertNothingSent();
    }

    public function test_check_is_rate_limited_without_repeating_provider_request(): void
    {
        $actor = $this->staff();
        $payment = $this->payment();
        $this->provider($payment, ['status' => 'PENDING']);
        app(IkhokhaReconciliationService::class)->check($payment, $actor);
        try {
            app(IkhokhaReconciliationService::class)->check($payment, $actor);
            $this->fail('Expected rate-limit validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment', $exception->errors());
        }
        Http::assertSentCount(1);
    }

    public function test_cancelled_order_payment_is_recorded_without_restarting_fulfillment(): void
    {
        $actor = $this->staff();
        $payment = $this->payment();
        $payment->order->update(['status' => 'cancelled']);
        $this->provider($payment);
        $this->assertSame('paid', app(IkhokhaReconciliationService::class)->check($payment, $actor));
        $this->assertSame('cancelled', $payment->order->fresh()->status);
        $this->assertSame(3, Product::first()->inventory_count);
        Notification::assertNothingSent();
    }

    public function test_concurrent_callback_does_not_duplicate_settlement(): void
    {
        $actor = $this->staff();
        $payment = $this->payment();
        Http::fake(function () use ($payment) {
            app(OrderPaymentService::class)->markPaid($payment);

            return Http::response(['paylinkID' => $payment->gateway_reference, 'status' => 'PAID', 'amount' => 10000]);
        });
        $this->assertSame('already_paid', app(IkhokhaReconciliationService::class)->check($payment, $actor));
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('inventory_logs', 1);
        Notification::assertCount(1);
    }

    public function test_changed_local_amount_and_currency_cannot_settle(): void
    {
        $actor = $this->staff();
        $payment = $this->payment();
        $payment->order->update(['currency' => 'USD']);
        $this->provider($payment);
        $this->assertSame('mismatch', app(IkhokhaReconciliationService::class)->check($payment, $actor));
        $this->assertSame('pending', $payment->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_audit_failure_rolls_back_payment_inventory_and_receipt(): void
    {
        $actor = $this->staff();
        $payment = $this->payment();
        $this->provider($payment);
        PaymentStatusCheck::creating(fn () => throw new \RuntimeException('Synthetic audit failure'));
        try {
            app(IkhokhaReconciliationService::class)->check($payment, $actor);
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        } finally {
            PaymentStatusCheck::flushEventListeners();
        }
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(3, Product::first()->inventory_count);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('order_receipts', 0);
        Notification::assertNothingSent();
    }

    public function test_admin_action_verifies_payment_and_shows_audit_result(): void
    {
        $this->staff();
        $payment = $this->payment();
        $this->provider($payment);
        Livewire::test(ListPaymentTransactions::class)->assertOk()
            ->callTableAction('verifyPayment', $payment)->assertHasNoTableActionErrors()
            ->assertSee('paid');
        $this->assertSame('paid', $payment->fresh()->status);
    }

    public function test_read_only_staff_cannot_invoke_payment_verification(): void
    {
        $this->staff(false);
        $payment = $this->payment();
        Livewire::test(ListPaymentTransactions::class)->assertOk()
            ->assertTableActionHidden('verifyPayment', $payment);
        try {
            app(IkhokhaReconciliationService::class)->check($payment, auth()->user());
            $this->fail('Expected authorization failure.');
        } catch (AuthorizationException $exception) {
            $this->assertTrue(true);
        }
        Http::assertNothingSent();
    }

    public function test_customer_cannot_read_payment_admin_or_verify_even_with_order_permissions(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $payment = $this->payment();
        $this->get('/admin/payment-transactions')->assertForbidden();
        try {
            app(IkhokhaReconciliationService::class)->check($payment, $actor);
            $this->fail('Expected role denial.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        Http::assertNothingSent();
    }
}
