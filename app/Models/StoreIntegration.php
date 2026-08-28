<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreIntegration extends Model
{
    protected $primaryKey = 'provider';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['provider', 'credentials', 'configuration', 'version', 'updated_by'];

    protected $hidden = ['credentials'];

    protected $casts = ['credentials' => 'encrypted:array', 'configuration' => 'array', 'version' => 'integer'];
}
