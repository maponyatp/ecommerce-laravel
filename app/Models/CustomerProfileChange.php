<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerProfileChange extends Model
{
    protected $fillable = ['customer_profile_id', 'actor_id', 'version', 'before_values', 'after_values'];

    protected $casts = ['version' => 'integer', 'before_values' => 'array', 'after_values' => 'array'];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
