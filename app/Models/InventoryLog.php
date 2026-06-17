<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryLog extends Model
{
    protected $fillable = [
    'product_id', 'color_id', 'size', 'previous_stock', 
    'adjustment_amount', 'new_stock', 'reason', 'admin_id'
];
}
