<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\CustomerOrderStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class OrderConfirmationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $order;

    /**
     * Create a new notification instance.
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order;
        $status = CustomerOrderStatus::describe($order);

        $mailMessage = (new MailMessage)
            ->subject(($status['confirmed'] ? 'Order Confirmation' : 'Order update').' #'.$order->id)
            ->greeting($status['confirmed'] ? 'Thank you for your order!' : $status['title'])
            ->line($status['message'])
            ->line('Order Number: #'.$order->id)
            ->line('Total Amount: '.$order->formatMoney($order->total_amount));

        if ($order->shipping_address) {
            $mailMessage->line('Shipping Address: '.$order->shipping_address);
        }

        if ($order->delivery_window_label) {
            $mailMessage->line('Requested delivery window: '.$order->delivery_window_label);
            $mailMessage->line($status['confirmed'] && CustomerOrderStatus::hasConfirmedDeliveryBooking($order)
                ? 'Delivery booking confirmed.'
                : 'This delivery window is NOT confirmed. Contact the shop to arrange an available window; please do not pay again.');
        }

        if ($order->shippingMethod) {
            $mailMessage->line('Shipping Method: '.$order->shippingMethod->name);
            if ($status['confirmed']) {
                $mailMessage->line('Estimated Delivery: '.$order->shippingMethod->estimated_delivery_time);
            }
        }

        // Add order items
        $mailMessage->line('**Order Items:**');
        foreach ($order->items as $item) {
            $mailMessage->line('- '.($item->product_name_snapshot ?? $item->product->name ?? 'Product #'.$item->product_id).($item->sku_snapshot ? ' [SKU: '.$item->sku_snapshot.']' : '').' (Qty: '.$item->quantity.') - '.$order->formatMoney($item->price * $item->quantity));
        }

        // Check for downloadable products
        $hasDownloadable = $order->items->contains(function ($item) {
            return $item->product->is_downloadable ?? false;
        });

        if ($hasDownloadable && $status['confirmed']) {
            $mailMessage->line('Open your order details below to download your digital products. No account is needed when using this private email link.');
        }

        if ($order->shipping_address) {
            $mailMessage->line('Your delivery details are available on your order page.');
        }
        $mailMessage->action('View Order Details', $this->confirmationUrl($order))
            ->line('Thank you for shopping with us!');

        return $mailMessage;
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'order_id' => $this->order->id,
            'total_amount' => $this->order->total_amount,
        ];
    }

    private function confirmationUrl(Order $order): string
    {
        return URL::temporarySignedRoute(
            'checkout.confirmation',
            now()->addDays(30),
            ['order' => $order->id],
        );
    }
}
