<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderIssue extends Model
{
    public const CATEGORIES = [
        'damaged_flowers' => 'Damaged or poor-quality flowers',
        'missing_delivery' => 'Delivery has not arrived',
        'wrong_items' => 'Wrong or missing items',
        'delivery_question' => 'Delivery question',
        'payment_question' => 'Payment or refund enquiry',
        'other' => 'Other order enquiry',
    ];

    public const STATUSES = ['open' => 'Open', 'investigating' => 'Under review',
        'waiting_customer' => 'Awaiting your reply', 'resolved' => 'Support case closed'];

    protected $fillable = ['order_id', 'active_order_id', 'submission_key', 'category', 'order_item_id',
        'quantity', 'status', 'version', 'resolved_at'];

    protected $casts = ['quantity' => 'integer', 'version' => 'integer', 'resolved_at' => 'datetime'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function messages()
    {
        return $this->hasMany(OrderIssueMessage::class);
    }
}
