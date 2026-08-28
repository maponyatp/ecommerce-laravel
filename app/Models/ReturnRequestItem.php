<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnRequestItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_request_id',
        'order_item_id',
        'quantity',
        'condition',
        'notes',
        'product_name_snapshot',
        'received_quantity',
        'disposition',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'received_quantity' => 'integer',
    ];

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
