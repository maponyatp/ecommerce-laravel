<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentStatusCheck extends Model
{
    protected $fillable = ['payment_transaction_id', 'checked_by', 'outcome', 'provider_status', 'amount_minor'];
}
