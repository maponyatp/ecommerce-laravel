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

    /** Validate persisted configuration too: older imports may bypass admin validation. */
    public function isConfiguredForCheckout(): bool
    {
        if (! $this->is_active) {
            return false;
        }
        foreach (['base_rate', 'weight_rate', 'max_weight'] as $field) {
            $value = $this->{$field};
            if ($value === null || ! is_finite($value) || $value < 0 || $value > 999999.99) {
                return false;
            }
        }
        $codes = $this->postal_codes;
        if ($codes === null) {
            return true;
        }
        if (! is_array($codes) || ! array_is_list($codes)) {
            return false;
        }
        foreach ($codes as $code) {
            if (! is_string($code) || preg_match('/^[0-9]{4}$/D', $code) !== 1) {
                return false;
            }
        }

        return true;
    }
}
