<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Refund extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'amount',
        'reason',
        'notes',
        'status',
        'refund_method',
        'transaction_id',
        'processed_by',
        'processed_at',
        'restock_items',
    ];

    protected $casts = [
        'version' => 'integer',
        'tax_amount' => 'decimal:2',
        'external_completed_at' => 'datetime',
        'amount' => 'decimal:2',
        'processed_at' => 'datetime',
        'restock_items' => 'boolean',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function creditNote()
    {
        return $this->hasOne(CreditNote::class);
    }

    public function changes()
    {
        return $this->hasMany(RefundChange::class);
    }

    public function paymentTransaction()
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $refund): void {
            if ($refund->getRawOriginal('version') !== null
                && (in_array($refund->getRawOriginal('status'), ['completed', 'cancelled'], true)
                    || $refund->isDirty(['order_id', 'invoice_id', 'payment_transaction_id', 'request_key', 'request_hash', 'amount', 'tax_amount', 'currency', 'reason', 'refund_method', 'requested_by', 'restock_items']))) {
                throw new \LogicException('Recorded refund details cannot be rewritten.');
            }
        });
        static::deleting(function (self $refund): void {
            if ($refund->version !== null) {
                throw new \LogicException('Refund records cannot be deleted.');
            }
        });
    }

    /**
     * Process the refund
     */
    public function process(?int $userId = null): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        throw new \LogicException('Refund processing is disabled until a gateway-confirmed refund workflow is configured. No money, stock or order status has been changed.');
    }
}
