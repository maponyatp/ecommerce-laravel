<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliverySlot extends Model
{
    protected $fillable = ['shipping_method_id', 'starts_at', 'ends_at', 'booking_closes_at', 'capacity', 'is_active', 'version'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'booking_closes_at' => 'datetime',
        'capacity' => 'integer', 'is_active' => 'boolean', 'version' => 'integer'];

    public function shippingMethod()
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    public function bookings()
    {
        return $this->hasMany(DeliveryBooking::class);
    }

    public function getWindowLabelAttribute(): string
    {
        $timezone = config('commerce.delivery_timezone');

        return $this->starts_at->copy()->timezone($timezone)->format('D, d M Y · H:i')
            .'–'.$this->ends_at->copy()->timezone($timezone)->format('H:i').' (South Africa)';
    }
}
