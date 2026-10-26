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
        'ndr_status',
        'ndr_reason',
        'ndr_updated_at',
        'expected_delivery_at',
        'last_tracked_at',
        'last_scan_remarks',
        'last_scan_location',
        'shipment_snapshot',
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

        // NEW
        'placed_at',
        'label_generated_at',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'invoice_date' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'ndr_updated_at' => 'datetime',
        'expected_delivery_at' => 'datetime',
        'last_tracked_at' => 'datetime',
        'placed_at' => 'datetime',
        'label_generated_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'shipment_snapshot' => 'array',
        'is_pincode_serviceable' => 'boolean',
        'amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'shipping' => 'decimal:2',
        'tax' => 'decimal:2',
    ];

    // Sent in every JSON response so the admin UI can show/hide the Cancel button.
    protected $appends = ['is_cancellable', 'cancellation_block_reason'];

    public const SHIPPED_OR_LATER_STATUSES = [
        'Shipped', 'In Transit', 'Out For Delivery', 'Delivered', 'RTO', 'Refunded',
    ];

    // Only orders that were actually placed: COD, or prepaid that was paid/refunded.
    public function scopePlaced($query)
    {
        return $query->where(function ($q) {
            $q->whereNotNull('placed_at')
                ->orWhere('payment_status', 'Paid');
        });
    }

    // Started checkout but never completed payment / COD confirmation.
    public function scopeUpcoming($query)
    {
        return $query->whereNull('placed_at')
            ->where('payment_status', '!=', 'Paid')
            ->whereNotIn('delivery_status', ['Cancelled']);
    }

    /**
     * null  => admin may cancel.
     * string => reason why cancel is blocked.
     */
    public function cancellationBlockReason(): ?string
    {
        if ($this->delivery_status === 'Cancelled') {
            return 'Order is already cancelled.';
        }

        if (in_array($this->delivery_status, self::SHIPPED_OR_LATER_STATUSES, true) || !empty($this->shipped_at)) {
            return "Order is already '{$this->delivery_status}' and can no longer be cancelled.";
        }

        if (!empty($this->awb_number) || !empty($this->waybill)) {
            return 'AWB has already been generated for this order, so it can no longer be cancelled.';
        }

        if (!empty($this->label_generated_at)) {
            return 'Shipping label has already been generated for this order, so it can no longer be cancelled.';
        }

        return null;
    }

    public function getIsCancellableAttribute(): bool
    {
        return $this->cancellationBlockReason() === null;
    }

    public function getCancellationBlockReasonAttribute(): ?string
    {
        return $this->cancellationBlockReason();
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
