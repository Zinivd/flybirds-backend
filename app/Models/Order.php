<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'order_id', 'invoice_number', 'invoice_date',
        'waybill', 'awb_number', 'ewbn',
        'shipment_status', 'delhivery_status',
        'ndr_status', 'ndr_reason', 'ndr_updated_at',
        'expected_delivery_at', 'last_tracked_at',
        'last_scan_remarks', 'last_scan_location', 'shipment_snapshot',
        'is_pincode_serviceable',
        'customer_id', 'customer_name', 'customer_email', 'customer_phone',
        'seller_name', 'amount', 'subtotal', 'discount', 'shipping', 'tax',
        'delivery_status', 'payment_method', 'payment_status',
        'shipping_address', 'shipping_pincode', 'shipping_city', 'shipping_state',
        'billing_address', 'shipped_at', 'delivered_at',
    ];

    protected $casts = [
        'invoice_date' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'ndr_updated_at' => 'datetime',
        'expected_delivery_at' => 'datetime',
        'last_tracked_at' => 'datetime',
        'shipment_snapshot' => 'array',
        'is_pincode_serviceable' => 'boolean',
        'amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'shipping' => 'decimal:2',
        'tax' => 'decimal:2',
    ];

    // Only orders that were actually placed: COD, or prepaid that was paid/refunded.
    public function scopePlaced($query)
    {
        return $query->where(function ($w) {
            $w->whereRaw('LOWER(payment_method) = ?', ['cod'])
              ->orWhereIn('payment_status', ['Paid', 'Refunded']);
        });
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_table_id');
    }

    public function customer()
    {
        return $this->belongsTo(FlyUser::class, 'customer_id', 'user_id');
    }

    public function trackingEvents()
    {
        return $this->hasMany(OrderTrackingEvent::class, 'order_table_id')->orderByDesc('scanned_at');
    }
}
