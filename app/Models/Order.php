<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
    'order_id',
    'invoice_number',
    'invoice_date',
    'waybill',
    'awb_number',
    'ewbn',
    'shipment_status',
    'delhivery_status',
    'ndr_status',        // ADD
    'ndr_reason',         // ADD
    'ndr_updated_at',     // ADD
    'is_pincode_serviceable',
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
    'shipping_pincode',
    'shipping_city',
    'shipping_state',
    'billing_address',
    'shipped_at',
    'delivered_at',
];

protected $casts = [
    'invoice_date' => 'datetime',
    'shipped_at' => 'datetime',
    'delivered_at' => 'datetime',
    'ndr_updated_at' => 'datetime', // ADD
    'is_pincode_serviceable' => 'boolean',
    'amount' => 'decimal:2',
    'subtotal' => 'decimal:2',
    'discount' => 'decimal:2',
    'shipping' => 'decimal:2',
    'tax' => 'decimal:2',
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