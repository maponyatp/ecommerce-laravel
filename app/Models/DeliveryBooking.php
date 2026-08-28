<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DeliveryBooking extends Model
{
    protected $fillable = ['order_id', 'delivery_slot_id', 'status', 'expires_at'];

    protected $casts = ['expires_at' => 'datetime'];

    public function scopeOccupying(Builder $query): Builder
    {
        return $query->where(fn ($query) => $query->where('status', 'confirmed')
            ->orWhere(fn ($query) => $query->where('status', 'held')->where('expires_at', '>', now())));
    }
}
