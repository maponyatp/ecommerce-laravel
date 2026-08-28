<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

class OrderItem extends Model
{
    use HasFactory;

    protected $table = 'order_items';

    protected $fillable = [
        'product_variant_id',
        'sku_snapshot',
        'options_snapshot',
        'product_name_snapshot',
        'is_downloadable_snapshot',
        'order_id',
        'product_id',
        'quantity',
        'price',
        'download_link',
        'download_expires_at',
        'download_count',
        'download_path',
        'download_limit',
    ];

    protected $casts = [
        'options_snapshot' => 'array',
        'is_downloadable_snapshot' => 'boolean',
        'download_expires_at' => 'datetime',
        'download_count' => 'integer',
        'download_limit' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Check if the download link is still valid
     */
    public function isDownloadValid(): bool
    {
        if (! $this->download_link || ! $this->download_path || ! $this->download_expires_at) {
            return false;
        }

        return $this->download_expires_at->isFuture()
            && ($this->download_limit === null || $this->download_count < $this->download_limit)
            && $this->order->payment_status === 'paid'
            && ! in_array($this->order->status, ['cancelled', 'refunded'], true);
    }

    public function downloadUrl(): ?string
    {
        if (! $this->isDownloadValid()) {
            return null;
        }

        return URL::temporarySignedRoute('downloads.file',
            $this->download_expires_at->min(now()->addMinutes(5)),
            ['item' => $this->id, 'token' => $this->download_link]);
    }
}
