<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'order_table_id',
        'razorpay_order_id',
        'razorpay_payment_id',
        'razorpay_signature',
        'amount',
        'currency',
        'status',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_table_id');
    }
}
