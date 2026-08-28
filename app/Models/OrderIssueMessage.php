<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderIssueMessage extends Model
{
    protected $fillable = ['order_issue_id', 'author_id', 'author_type', 'body', 'is_internal', 'submission_key'];

    protected $casts = ['is_internal' => 'boolean'];

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
