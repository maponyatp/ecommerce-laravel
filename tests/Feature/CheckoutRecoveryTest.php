<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\InventoryLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderReceipt;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ShippingMethod;
use App\Services\OrderPaymentService;
use App\Services\OrderReceiptService;
use App\Services\StockReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CheckoutRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::preventStrayRequests();
    }

    private function product(int $stock = 1, float $price = 100): Product
    {
        $category = ProductCategory::create(['name' => 'Flowers', 'slug' => 'flowers-'.uniqid()]);

        return Product::create(['name' => 'Rose bouquet', 'slug' => 'roses-'.uniqid(), 'category_id' => $category->id,
            'price' => $price, 'inventory_count' => $stock, 'is_downloadable' => false]);
    }

    private function order(Product $product, array $overrides = [], int $quantity = 1): Order
    {
        return DB::transaction(function () use ($product, $overrides, $quantity) {
            $order = Order::create(array_merge([
                'customer_email' => 'buyer@example.test', 'order_date' => now(), 'currency' => 'ZAR',
                'total_amount' => $product->price * $quantity, 'payment_status' => 'pending',
                'payment_method' => 'ikhokha', 'shipping_status' => 'unfulfilled', 'status' => 'pending',
            ], $overrides));
            $order->items()->create(['product_id' => $product->id, 'price' => $product->price, 'quantity' => $quantity]);
            app(StockReservationService::class)->reserve($order);

            return $order->fresh();
        });
    }

    private function transaction(Order $order): PaymentTransaction
    {
        return PaymentTransaction::create(['order_id' => $order->id, 'gateway' => 'ikhokha',
            'external_transaction_id' => 'PAY-'.uniqid(), 'amount' => $order->total_amount, 'currency' => 'ZAR', 'status' => 'pending']);
    }

    private function assertInvalid(callable $callback, string $field): void
    {
        try {
            $callback();
            $this->fail('Expected validation to reject the reservation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_last_bouquet_is_held_without_deducting_on_hand_stock(): void
    {
        $product = $this->product();
        $order = $this->order($product);
        $this->assertSame(1, $product->fresh()->inventory_count);
        $this->assertSame(0, app(StockReservationService::class)->available($product));
        $this->assertSame('held', $order->stock_reservation_status);
        $this->assertInvalid(fn () => $this->order($product), 'cart');
        $this->assertSame(1, Order::count());
        $this->assertDatabaseCount('stock_reservations', 1);
    }

    public function test_settlement_commits_once_and_replay_preserves_later_fulfillment(): void
    {
        $product = $this->product(3);
        $order = $this->order($product, [], 2);
        $transaction = $this->transaction($order);
        $service = app(OrderPaymentService::class);
        $service->markPaid($transaction);
        $order->refresh()->update(['status' => 'completed', 'shipping_status' => 'delivered']);
        $service->markPaid($transaction);
        $this->assertSame(1, $product->fresh()->inventory_count);
        $this->assertSame(1, InventoryLog::count());
        $this->assertSame(1, Invoice::count());
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('committed', $order->fresh()->stock_reservation_status);
        $this->assertSame('sent', OrderReceipt::first()->status);
        Notification::assertCount(1);
    }

    public function test_verified_failure_releases_holds_without_restocking_twice(): void
    {
        $product = $this->product();
        $order = $this->order($product);
        $transaction = $this->transaction($order);
        app(OrderPaymentService::class)->markFailed($transaction);
        app(OrderPaymentService::class)->markFailed($transaction);
        $this->assertSame(1, app(StockReservationService::class)->available($product->fresh()));
        $this->assertSame(1, $product->fresh()->inventory_count);
        $this->assertSame('released', $order->fresh()->stock_reservation_status);
        $this->assertSame(0, InventoryLog::count());
    }

    public function test_expiry_releases_availability_and_does_not_claim_a_payment_failed(): void
    {
        $product = $this->product();
        $order = $this->order($product);
        $this->travel(31)->minutes();
        $this->assertSame(1, app(StockReservationService::class)->available($product));
        $this->artisan('commerce:recover-checkouts')->assertExitCode(0);
        $this->assertSame('checkout_expired', $order->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame('released', $order->fresh()->stock_reservation_status);
        $this->assertSame(0, app(StockReservationService::class)->expire());
    }

    public function test_late_payment_cannot_take_another_customers_reserved_stock(): void
    {
        $product = $this->product();
        $late = $this->order($product);
        $transaction = $this->transaction($late);
        $this->travel(31)->minutes();
        $new = $this->order($product);
        app(OrderPaymentService::class)->markPaid($transaction);
        $this->assertSame('paid', $late->fresh()->payment_status);
        $this->assertSame('payment_received_stock_review', $late->fresh()->status);
        $this->assertNull($late->fresh()->inventory_committed_at);
        $this->assertSame('held', $new->fresh()->stock_reservation_status);
        $this->assertSame(1, $product->fresh()->inventory_count);
        app(OrderPaymentService::class)->markPaid($this->transaction($new));
        $this->assertSame(0, $product->fresh()->inventory_count);
    }

    public function test_late_payment_can_use_unreserved_stock_when_still_available(): void
    {
        $product = $this->product();
        $order = $this->order($product);
        $transaction = $this->transaction($order);
        $this->travel(31)->minutes();
        app(StockReservationService::class)->expire();
        app(OrderPaymentService::class)->markPaid($transaction);
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame(0, $product->fresh()->inventory_count);
    }

    public function test_cancelled_order_is_not_reactivated_by_late_payment(): void
    {
        $product = $this->product();
        $order = $this->order($product);
        $transaction = $this->transaction($order);
        $order->update(['status' => 'cancelled']);
        app(OrderPaymentService::class)->markPaid($transaction);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(1, $product->fresh()->inventory_count);
        $this->assertSame(0, OrderReceipt::count());
    }

    public function test_limited_coupon_is_held_and_released_with_checkout(): void
    {
        $product = $this->product(10);
        $coupon = Coupon::create(['code' => 'ONLYONE', 'type' => 'fixed', 'value' => 5, 'max_uses' => 1,
            'valid_from' => now()->subDay(), 'valid_until' => now()->addDay()]);
        $order = $this->order($product, ['coupon_code' => $coupon->code, 'discount_amount' => 5, 'total_amount' => 95]);
        $this->assertFalse($coupon->fresh()->isValid());
        $this->assertInvalid(fn () => $this->order($product, ['coupon_code' => $coupon->code]), 'coupon');
        app(OrderPaymentService::class)->markFailed($this->transaction($order));
        $this->assertTrue($coupon->fresh()->isValid());
    }

    public function test_email_failure_cannot_rollback_payment_inventory_or_invoice(): void
    {
        $product = $this->product();
        $order = $this->order($product);
        Notification::shouldReceive('sendNow')->once()->andThrow(new \RuntimeException('secret smtp detail'));
        app(OrderPaymentService::class)->markPaid($this->transaction($order));
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(0, $product->fresh()->inventory_count);
        $this->assertSame(1, Invoice::count());
        $this->assertNull($order->fresh()->confirmation_sent_at);
        $receipt = OrderReceipt::first();
        $this->assertSame('pending', $receipt->status);
        $this->assertStringNotContainsString('secret smtp', $receipt->last_error);
        $this->assertTrue($receipt->available_at->isFuture());
    }

    public function test_receipt_recovery_retries_only_due_records_and_marks_transport_acceptance(): void
    {
        $order = $this->order($this->product());
        $order->update(['payment_status' => 'paid']);
        $receipt = app(OrderReceiptService::class)->enqueue($order);
        $receipt->update(['available_at' => now()->addMinutes(2)]);
        $this->assertSame(0, app(OrderReceiptService::class)->recover());
        $this->travel(3)->minutes();
        $this->assertSame(1, app(OrderReceiptService::class)->recover());
        $this->assertSame('sent', $receipt->fresh()->status);
        $this->assertNotNull($order->fresh()->confirmation_sent_at);
        $this->assertSame(0, app(OrderReceiptService::class)->recover());
        Notification::assertCount(1);
    }

    public function test_stuck_receipt_claim_is_recovered_but_active_claim_is_not_duplicated(): void
    {
        $order = $this->order($this->product(), ['payment_status' => 'paid']);
        $receipt = app(OrderReceiptService::class)->enqueue($order);
        $receipt->update(['status' => 'sending', 'locked_at' => now(), 'lock_token' => 'active']);
        app(OrderReceiptService::class)->deliver($receipt->id);
        Notification::assertNothingSent();
        $this->travel(11)->minutes();
        app(OrderReceiptService::class)->recover();
        $this->assertSame('sent', $receipt->fresh()->status);
        Notification::assertCount(1);
    }

    public function test_refunded_receipts_are_suppressed_and_historical_sent_orders_not_backfilled(): void
    {
        $order = $this->order($this->product());
        $receipt = app(OrderReceiptService::class)->enqueue($order);
        $order->update(['payment_status' => 'refunded']);
        app(OrderReceiptService::class)->deliver($receipt->id);
        $this->assertSame('suppressed', $receipt->fresh()->status);
        $historical = $this->order($this->product(), ['confirmation_sent_at' => now()]);
        $this->assertNull(app(OrderReceiptService::class)->enqueue($historical));
        Notification::assertNothingSent();
    }

    public function test_retry_limit_requires_manual_retry_and_never_resends_sent_receipts(): void
    {
        $order = $this->order($this->product(), ['payment_status' => 'paid']);
        $receipt = app(OrderReceiptService::class)->enqueue($order);
        $receipt->update(['attempts' => 10]);
        app(OrderReceiptService::class)->deliver($receipt->id);
        $this->assertSame('failed', $receipt->fresh()->status);
        $this->assertTrue(app(OrderReceiptService::class)->retryFailed($order));
        app(OrderReceiptService::class)->recover();
        $this->assertSame('sent', $receipt->fresh()->status);
        $this->assertFalse(app(OrderReceiptService::class)->retryFailed($order));
        Notification::assertCount(1);
    }

    public function test_free_order_uses_the_same_atomic_stock_and_receipt_path(): void
    {
        $product = $this->product(2, 0);
        $order = $this->order($product, ['payment_method' => 'free']);
        app(OrderPaymentService::class)->settleFree($order);
        app(OrderPaymentService::class)->settleFree($order);
        $this->assertSame(1, $product->fresh()->inventory_count);
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, InventoryLog::count());
        Notification::assertCount(1);
    }

    private function address(ShippingMethod $method): array
    {
        return ['email' => 'buyer@example.test', 'shipping_method_id' => $method->id,
            'shipping_address' => '1 Rose Street', 'shipping_city' => 'Johannesburg', 'shipping_country' => 'ZA',
            'shipping_postal_code' => '2001', 'delivery_contact_name' => 'Recipient', 'delivery_phone' => '+27821234567',
            'payment_method' => 'ikhokha'];
    }

    public function test_repeated_checkout_reuses_the_payment_link_and_does_not_reserve_or_charge_twice(): void
    {
        $product = $this->product(2);
        $method = ShippingMethod::create(['name' => 'Local', 'base_rate' => 0, 'weight_rate' => 0, 'max_weight' => 10]);
        config(['services.ikhokha.app_id' => 'test-app', 'services.ikhokha.app_secret' => 'test-secret']);
        Http::fake(['https://api.ikhokha.com/*' => Http::response(['responseCode' => '00', 'paylinkID' => 'hold-link', 'paylinkUrl' => 'https://securepay.ikhokha.red/hold'])]);
        $address = $this->address($method);
        $this->withSession(['cart' => [$product->id => ['quantity' => 1]]])
            ->post(route('checkout.process'), $address)->assertRedirect('https://securepay.ikhokha.red/hold');
        $this->post(route('checkout.process'), $address)->assertRedirect('https://securepay.ikhokha.red/hold');
        $this->assertSame(1, Order::count());
        $this->assertSame(1, PaymentTransaction::count());
        $this->assertDatabaseCount('stock_reservations', 1);
        Http::assertSentCount(1);
    }

    public function test_ambiguous_gateway_failure_keeps_hold_and_does_not_start_another_link(): void
    {
        $product = $this->product(2);
        $method = ShippingMethod::create(['name' => 'Local', 'base_rate' => 0, 'weight_rate' => 0, 'max_weight' => 10]);
        config(['services.ikhokha.app_id' => 'test-app', 'services.ikhokha.app_secret' => 'test-secret']);
        Http::fake(['https://api.ikhokha.com/*' => Http::response([], 503)]);
        $address = $this->address($method);
        $this->withSession(['cart' => [$product->id => ['quantity' => 1]]])
            ->post(route('checkout.process'), $address)->assertRedirect();
        $order = Order::first();
        $this->assertSame('payment_review', $order->status);
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('held', $order->stock_reservation_status);
        $this->post(route('checkout.process'), $address)->assertRedirect();
        Http::assertSentCount(1);
        $this->assertSame(1, Order::count());
    }

    public function test_stock_commit_is_all_or_nothing_after_an_admin_stock_change(): void
    {
        $first = $this->product(2);
        $second = $this->product(2);
        $order = DB::transaction(function () use ($first, $second) {
            $order = Order::create(['customer_email' => 'buyer@example.test', 'order_date' => now(),
                'currency' => 'ZAR', 'total_amount' => 200, 'payment_status' => 'pending',
                'payment_method' => 'ikhokha', 'shipping_status' => 'unfulfilled', 'status' => 'pending']);
            foreach ([$first, $second] as $product) {
                $order->items()->create(['product_id' => $product->id, 'price' => 100, 'quantity' => 1]);
            }
            app(StockReservationService::class)->reserve($order);

            return $order;
        });
        $second->update(['inventory_count' => 0]);
        app(OrderPaymentService::class)->markPaid($this->transaction($order));
        $this->assertSame(2, $first->fresh()->inventory_count);
        $this->assertSame(0, InventoryLog::count());
        $this->assertSame('payment_received_stock_review', $order->fresh()->status);
    }

    public function test_changed_coupon_amount_is_rechecked_under_reservation_lock(): void
    {
        $product = $this->product(10);
        Coupon::create(['code' => 'CHANGED', 'type' => 'fixed', 'value' => 5,
            'valid_from' => now()->subDay(), 'valid_until' => now()->addDay()]);
        $this->assertInvalid(fn () => $this->order($product, ['coupon_code' => 'CHANGED', 'discount_amount' => 50]), 'coupon');
        $this->assertSame(0, Order::count());
    }

    public function test_second_payment_attempt_cannot_reopen_completed_order_or_deduct_again(): void
    {
        $product = $this->product(3);
        $order = $this->order($product);
        app(OrderPaymentService::class)->markPaid($this->transaction($order));
        $order->refresh()->update(['status' => 'completed', 'shipping_status' => 'delivered']);
        app(OrderPaymentService::class)->markPaid($this->transaction($order));
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(2, $product->fresh()->inventory_count);
        $this->assertSame(1, InventoryLog::count());
        Notification::assertCount(1);
    }
}
