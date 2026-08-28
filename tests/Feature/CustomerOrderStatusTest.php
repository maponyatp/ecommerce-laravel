<?php

namespace Tests\Feature;

use App\Models\DeliveryBooking;
use App\Models\Order;
use App\Notifications\OrderConfirmationNotification;
use App\Support\CustomerOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerOrderStatusTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $state): Order
    {
        Notification::fake();

        return Order::create($state + ['customer_email' => 'buyer@example.test', 'order_date' => now(),
            'currency' => 'ZAR', 'total_amount' => 100, 'payment_method' => 'ikhokha',
            'payment_status' => 'paid', 'status' => 'processing', 'shipping_status' => 'unfulfilled',
            'inventory_committed_at' => now(), 'payment_processed_at' => now()])->fresh();
    }

    public static function states(): array
    {
        return [
            'normal paid order' => [[], 'Order confirmed!', 'Your order has been confirmed.', true],
            'stock review' => [['status' => 'payment_received_stock_review'], 'Payment received — stock review required', 'stock needs manual review', false],
            'delivery review' => [['status' => 'payment_received_delivery_review'], 'Payment received — delivery review required', 'delivery window is not confirmed', false],
            'paid cancellation' => [['status' => 'cancelled'], 'Order cancelled', 'cancellation does not return money', false],
            'unpaid cancellation' => [['status' => 'cancelled', 'payment_status' => 'pending'], 'Order cancelled', 'does not verify an external payment', false],
            'refund payment state' => [['payment_status' => 'refunded'], 'Refund recorded', 'marked as refunded', false],
            'refund order state' => [['status' => 'refunded'], 'Refund recorded', 'marked as refunded', false],
            'expired checkout' => [['status' => 'checkout_expired', 'payment_status' => 'pending'], 'Reservation expired', 'does not cancel an external payment link', false],
            'uncertain payment' => [['status' => 'payment_review', 'payment_status' => 'pending'], 'Payment verification required', 'before starting another payment', false],
            'failed payment' => [['status' => 'payment_failed', 'payment_status' => 'failed'], 'Payment unsuccessful', 'bank shows a charge', false],
            'pending payment' => [['status' => 'awaiting_payment', 'payment_status' => 'pending'], 'Payment pending', 'not confirmed yet', false],
            'missing stock commitment' => [['inventory_committed_at' => null], 'Payment received — stock review required', 'stock needs manual review', false],
            'unknown paid fulfilment state' => [['status' => 'supplier_queued'], 'Payment received — order review required', 'fulfilment status needs review', false],
        ];
    }

    #[DataProvider('states')]
    public function test_signed_return_confirmation_and_receipt_describe_the_same_saved_state(array $state, string $title, string $message, bool $confirmed): void
    {
        $order = $this->order($state);
        $before = $order->attributesToArray();
        $return = URL::temporarySignedRoute('payments.ikhokha.return', now()->addHour(), ['order' => $order]);
        $this->get($return)->assertRedirect()->assertSessionHas($confirmed ? 'success' : 'warning',
            fn ($value) => is_string($value) && str_contains($value, $message));
        $url = URL::temporarySignedRoute('checkout.confirmation', now()->addHour(), ['order' => $order]);
        $html = $this->get($url)->assertOk()->getContent();
        $this->assertTrue(str_contains($html, e($title)), 'Missing customer status heading: '.$title);
        $this->assertTrue(str_contains($html, e($message)), 'Missing customer status explanation: '.$message);
        $mail = (new OrderConfirmationNotification($order->fresh()))->toMail(new AnonymousNotifiable);
        $this->assertSame(($confirmed ? 'Order Confirmation' : 'Order update').' #'.$order->id, $mail->subject);
        $this->assertStringContainsString($message, implode(' ', $mail->introLines));
        $this->assertSame($before, $order->fresh()->attributesToArray());
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertDatabaseCount('invoices', 0);
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_return_cancel_hint_does_not_claim_a_payment_was_cancelled(): void
    {
        $order = $this->order(['status' => 'awaiting_payment', 'payment_status' => 'pending']);
        $url = URL::temporarySignedRoute('payments.ikhokha.return', now()->addHour(), ['order' => $order, 'result' => 'cancelled']);
        $this->get($url)->assertRedirect()->assertSessionHas('warning',
            fn ($value) => str_contains($value, 'not confirmed yet') && ! str_contains($value, 'Payment was cancelled'));
        $this->assertSame('pending', $order->fresh()->payment_status);
        Http::assertNothingSent();
    }

    public function test_expired_hold_is_not_displayed_as_currently_reserved_before_recovery_runs(): void
    {
        $order = $this->order(['status' => 'awaiting_payment', 'payment_status' => 'pending',
            'stock_reservation_status' => 'held', 'stock_reserved_until' => now()->subMinute()]);
        $url = URL::temporarySignedRoute('checkout.confirmation', now()->addHour(), ['order' => $order]);
        $html = $this->get($url)->assertOk()->getContent();
        $this->assertTrue(str_contains($html, 'Reservation expired'));
        $this->assertFalse(str_contains($html, 'Stock is reserved until'));
        $this->assertSame('awaiting_payment', $order->fresh()->status);
    }

    public function test_unpaid_refund_page_does_not_offer_a_payment_retry(): void
    {
        $order = $this->order(['status' => 'refunded', 'payment_status' => 'refunded']);
        $url = URL::temporarySignedRoute('checkout.confirmation', now()->addHour(), ['order' => $order]);
        $html = $this->get($url)->assertOk()->getContent();
        $this->assertFalse(str_contains($html, 'Payment pending'));
        $this->assertFalse(str_contains($html, 'Check payment status'));
        $this->assertTrue(str_contains($html, 'Refund recorded'));
    }

    public function test_scheduled_delivery_requires_a_matching_confirmed_booking_and_fulfillable_order(): void
    {
        foreach ([null, 'held', 'released', 'mismatched', 'confirmed', 'cancelled', 'refunded', 'stock_review'] as $case) {
            $order = new Order(['status' => 'processing', 'payment_status' => 'paid', 'total_amount' => 100,
                'currency' => 'ZAR', 'inventory_committed_at' => now(), 'delivery_slot_id' => 12,
                'delivery_scheduled_at' => now()->addDay(), 'delivery_window_end' => now()->addDay()->addHour()]);
            $order->id = 123;
            $order->setRelation('items', collect());
            $order->setRelation('shippingMethod', null);
            $order->setRelation('deliveryBooking', $case === null ? null : new DeliveryBooking([
                'delivery_slot_id' => $case === 'mismatched' ? 13 : 12,
                'status' => in_array($case, ['held', 'released'], true) ? $case : 'confirmed',
                'expires_at' => now()->addHour(),
            ]));
            if (in_array($case, ['cancelled', 'refunded'], true)) {
                $order->status = $case;
            } elseif ($case === 'stock_review') {
                $order->status = 'payment_received_stock_review';
            }
            $confirmed = $case === 'confirmed';
            $this->assertSame($confirmed, CustomerOrderStatus::describe($order)['confirmed'], (string) $case);
            $html = view('orders.delivery-window', compact('order'))->render();
            $this->assertSame($confirmed, str_contains($html, 'Booking confirmed.'), (string) $case);
            $mail = (new OrderConfirmationNotification($order))->toMail(new AnonymousNotifiable);
            $this->assertSame($confirmed, str_contains(implode(' ', $mail->introLines), 'Delivery booking confirmed.'), (string) $case);
        }
        Http::assertNothingSent();
    }
}
