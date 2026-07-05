<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'order_id',
        'customer_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'seller_name',
        'amount',
        'subtotal',
        'discount',
        'shipping',
        'tax',
        'delivery_status',
        'payment_method',
        'payment_status',
        'shipping_address',
        'billing_address',
    ];

    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_table_id');
    }

    public function customer()
    {
        return $this->belongsTo(FlyUser::class, 'customer_id', 'user_id');
    }
}
