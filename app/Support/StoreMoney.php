<?php

namespace App\Support;

class StoreMoney
{
    public static function currency(): string
    {
        return config('commerce.currency', 'ZAR');
    }

    public static function format(mixed $amount, ?string $currency = null): string
    {
        return ($currency ?? self::currency()).' '.number_format((float) $amount, 2);
    }
}
