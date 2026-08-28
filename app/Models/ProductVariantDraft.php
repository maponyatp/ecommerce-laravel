<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariantDraft extends Model
{
    protected $fillable = ['product_id', 'sku', 'title', 'options', 'price', 'currency', 'archived', 'version'];

    protected $casts = ['options' => 'array', 'price' => 'decimal:2', 'archived' => 'boolean', 'version' => 'integer'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function changes()
    {
        return $this->hasMany(ProductVariantDraftChange::class);
    }
}
