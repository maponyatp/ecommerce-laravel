<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReturnRequestChange extends Model
{
    protected $fillable = ['return_request_id', 'actor_id', 'version', 'before_values', 'after_values', 'note'];

    protected $casts = ['version' => 'integer', 'before_values' => 'array', 'after_values' => 'array'];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
