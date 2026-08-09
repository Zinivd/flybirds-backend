<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class DelhiveryShipment extends Model
{
    protected $fillable = [
        'order_id', 'waybill', 'status', 'payment_mode',
        'total_amount', 'cod_amount', 'request_payload', 'response_payload',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];
}