<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PaymentTransaction extends Model
{
    protected $fillable = [
        'order_id',
        'gateway',
        'external_transaction_id',
        'gateway_reference',
        'amount',
        'currency',
        'status',
        'response_code',
        'request_payload',
        'response_payload',
        'paid_at',
        'failed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function statusChecks(): HasMany
    {
        return $this->hasMany(PaymentStatusCheck::class);
    }

    public function latestStatusCheck(): HasOne
    {
        return $this->hasOne(PaymentStatusCheck::class)->latestOfMany();
    }
}
