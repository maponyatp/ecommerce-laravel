<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FirewallRule extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'version' => 'integer'];
    public function scopeActive($query) { return $query->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())); }
}
