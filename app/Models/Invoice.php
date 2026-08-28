<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;

    protected $table = 'invoices';

    protected $fillable = [
        'order_id',
        'customer_id',
        'invoice_number',
        'invoice_date',
        'total_amount',
        'payment_status',
    ];

    protected $casts = [
        'invoice_date' => 'datetime',
        'document_snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $invoice): void {
            if ($invoice->getRawOriginal('document_snapshot') !== null
                && $invoice->isDirty(['document_snapshot', 'order_id', 'customer_id', 'invoice_number', 'invoice_date', 'total_amount'])) {
                throw new \LogicException('Issued invoice contents cannot be rewritten. Use a reviewed correction or credit-note workflow.');
            }
        });
        static::deleting(function (self $invoice): void {
            if ($invoice->document_snapshot !== null) {
                throw new \LogicException('Issued invoices must be retained.');
            }
        });
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function creditNotes()
    {
        return $this->hasMany(CreditNote::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class)->withPivot('quantity', 'price');
    }
}
