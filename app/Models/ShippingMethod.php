<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'requires_delivery_slot',
        'is_active',
        'postal_codes',
        'name',
        'description',
        'base_rate',
        'weight_rate',
        'max_weight',
        'estimated_delivery_time',
    ];

    protected $casts = [
        'requires_delivery_slot' => 'boolean',
        'is_active' => 'boolean',
        'postal_codes' => 'array',
        'base_rate' => 'float',
        'weight_rate' => 'float',
        'max_weight' => 'float',
    ];
}
