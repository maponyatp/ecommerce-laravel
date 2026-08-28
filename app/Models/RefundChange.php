<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RefundChange extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['data' => 'array', 'version' => 'integer', 'created_at' => 'datetime'];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Refund history cannot be rewritten.'));
        static::deleting(fn () => throw new \LogicException('Refund history cannot be deleted.'));
    }
}
