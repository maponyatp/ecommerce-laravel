<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderReceipt extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'available_at' => 'datetime', 'locked_at' => 'datetime', 'sent_at' => 'datetime', 'attempts' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
