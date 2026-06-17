<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductColorImage extends Model
{
    protected $fillable = [
        'product_color_variant_id',
        'image_url',
        'type',        // 'gallery' or 'thumbnail'
        'sort_order',
    ];

    public function colorVariant()
    {
        return $this->belongsTo(ProductColorVariant::class, 'product_color_variant_id');
    }
}