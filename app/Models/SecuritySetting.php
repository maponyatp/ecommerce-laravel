<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecuritySetting extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['version' => 'integer'];
}
