<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariantDraftChange extends Model
{
    protected $fillable = ['product_variant_draft_id', 'actor_id', 'version', 'before_values', 'after_values'];

    protected $casts = ['before_values' => 'array', 'after_values' => 'array', 'version' => 'integer'];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
