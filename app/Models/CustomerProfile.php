<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerProfile extends Model
{
    protected $fillable = ['identity_key', 'preferred_name', 'labels', 'staff_notes', 'version',
        'directory_team_key', 'directory_kind', 'directory_identity'];

    protected $casts = ['labels' => 'array', 'version' => 'integer'];

    public function changes()
    {
        return $this->hasMany(CustomerProfileChange::class);
    }
}
