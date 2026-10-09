<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderTrackingEvent extends Model
{
    protected $fillable = [
        'order_table_id', 'awb_number', 'status', 'status_code',
        'remarks', 'location', 'scanned_at', 'hash',
    ];

    protected $casts = ['scanned_at' => 'datetime'];
}
