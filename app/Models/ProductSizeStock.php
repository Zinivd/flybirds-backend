<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSizeStock extends Model
{
    protected $fillable = [
        'product_color_variant_id',
        'size',
        'sku',
        'price',
        'stock',
    ];

    protected $casts = [
        'price' => 'float',
        'stock' => 'integer',
    ];

    public function colorVariant()
    {
        return $this->belongsTo(ProductColorVariant::class, 'product_color_variant_id');
    }
}