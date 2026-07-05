<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'order_table_id',
        'product_name',
        'color',
        'size',
        'price',
        'quantity',
        'total',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_table_id');
    }
}
