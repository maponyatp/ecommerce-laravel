<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffSecurityLog extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['details' => 'array', 'created_at' => 'datetime'];
    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Security audit records cannot be rewritten.'));
        static::deleting(fn () => throw new \LogicException('Security audit records cannot be deleted.'));
    }
}
