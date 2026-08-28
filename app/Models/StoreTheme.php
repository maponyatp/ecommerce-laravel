<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreTheme extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'design' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Theme versions are immutable; upload a new version.'));
        static::deleting(fn () => throw new \LogicException('Preserve theme versions and activation history.'));
    }
}
