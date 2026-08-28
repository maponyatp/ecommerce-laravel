<?php

namespace App\Models;

use App\Support\StoreMoney;
use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;
    use IsTenantModel;

    protected $table = 'orders';

    public const WORK_QUEUES = [
        'to_prepare' => 'To prepare',
        'payment_attention' => 'Payment attention',
        'exceptions' => 'Stock / delivery review',
        'in_transit' => 'Out for delivery',
    ];

    public function scopeWorkQueue(Builder $query, string $queue): Builder
    {
        return match ($queue) {
            'to_prepare' => $query->where('payment_status', 'paid')->whereNotNull('inventory_committed_at')
                ->where('status', 'processing')->whereIn('shipping_status', ['unfulfilled', 'processing']),
            'payment_attention' => $query->where('payment_status', 'pending')
                ->whereIn('status', ['pending', 'awaiting_payment', 'payment_review', 'checkout_expired']),
            'exceptions' => $query->where('payment_status', 'paid')
                ->whereIn('status', ['payment_received_stock_review', 'payment_received_delivery_review']),
            'in_transit' => $query->where('payment_status', 'paid')->where('shipping_status', 'shipped')
                ->whereNotIn('status', ['cancelled', 'refunded']),
            default => $query,
        };
    }

    protected $fillable = [
        'billing_details',
        'invoice_context',
        'delivery_slot_id',
        'delivery_scheduled_at',
        'delivery_window_end',
        'checkout_key',
        'stock_reserved_until',
        'stock_reservation_status',
        'fulfillment_version',
        'delivery_carrier',
        'tracking_url',
        'shipped_at',
        'delivered_at',
        'currency',
        'delivery_contact_name',
        'delivery_phone',
        'shipping_country',
        'shipping_city',
        'shipping_region',
        'shipping_postal_code',
        'user_id',
        'customer_id',
        'customer_email',
        'order_date',
        'total_amount',
        'shipping_cost',
        'tax_amount',
        'discount_amount',
        'coupon_code',
        'payment_status',
        'shipping_status',
        'shipping_address',
        'shipping_method_id',
        'payment_method',
        'status',
        'is_dropshipped',
        'recipient_name',
        'recipient_email',
        'gift_message',
        'supplier_id',
        'supplier_order_reference',
        'supplier_tracking_number',
        'supplier_response',
        'payment_processed_at',
        'inventory_committed_at',
        'confirmation_sent_at',
    ];

    protected $casts = [
        'refund_total' => 'decimal:2',
        'fully_refunded' => 'boolean',
        'partially_refunded' => 'boolean',
        'billing_details' => 'array',
        'invoice_context' => 'array',
        'delivery_scheduled_at' => 'datetime',
        'delivery_window_end' => 'datetime',
        'stock_reserved_until' => 'datetime',
        'fulfillment_version' => 'integer',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'supplier_response' => 'array',
        'is_dropshipped' => 'boolean',
        'shipping_cost' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'payment_processed_at' => 'datetime',
        'inventory_committed_at' => 'datetime',
        'confirmation_sent_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function formatMoney(mixed $amount): string
    {
        return $this->currency
            ? StoreMoney::format($amount, $this->currency)
            : number_format((float) $amount, 2).' (currency not recorded)';
    }

    public function shippingMethod()
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function ($orders) use ($user) {
            $orders->where('user_id', $user->id);
            // Legacy/guest purchases require proven control of the receipt email.
            if ($user->hasVerifiedEmail()) {
                $orders->orWhere(fn ($legacy) => $legacy->whereNull('user_id')->where('customer_email', $user->email));
            }
        });
    }

    public function isAccessibleTo(User $user): bool
    {
        return static::query()->whereKey($this->id)->accessibleTo($user)->exists();
    }

    public function paymentTransactions()
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function invoice()
    {
        return $this->hasOne(Invoice::class);
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    public function hasPendingRefund(): bool
    {
        return $this->refunds()->whereNotNull('version')->where('status', 'pending')->exists();
    }

    public function issues()
    {
        return $this->hasMany(OrderIssue::class);
    }

    public function notes()
    {
        return $this->hasMany(OrderNote::class);
    }

    public function deliveryBooking()
    {
        return $this->hasOne(DeliveryBooking::class);
    }

    public function getDeliveryWindowLabelAttribute(): ?string
    {
        if (! $this->delivery_scheduled_at || ! $this->delivery_window_end) {
            return null;
        }
        $timezone = config('commerce.delivery_timezone');

        return $this->delivery_scheduled_at->copy()->timezone($timezone)->format('D, d M Y · H:i')
            .'–'.$this->delivery_window_end->copy()->timezone($timezone)->format('H:i').' (South Africa)';
    }

    public function receipt()
    {
        return $this->hasOne(OrderReceipt::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function getSafeTrackingUrlAttribute(): ?string
    {
        $url = $this->tracking_url;

        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PASS) ? $url : null;
    }
}
