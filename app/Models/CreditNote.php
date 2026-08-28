<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditNote extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['document_snapshot' => 'array', 'issued_at' => 'datetime'];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function refund()
    {
        return $this->belongsTo(Refund::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Issued credit notes cannot be rewritten.'));
        static::deleting(fn () => throw new \LogicException('Issued credit notes cannot be deleted.'));
    }
}
