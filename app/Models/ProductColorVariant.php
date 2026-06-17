<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductColorVariant extends Model
{
    protected $fillable = [
        'product_id',
        'color_id',
    ];

    // ─── Relationships ─────────────────────────────────────────────

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function color()
    {
        return $this->belongsTo(Color::class);
    }

    // Color → its own gallery + thumbnail images
    public function images()
    {
        return $this->hasMany(ProductColorImage::class, 'product_color_variant_id')
                    ->orderBy('sort_order');
    }

    public function galleryImages()
    {
        return $this->hasMany(ProductColorImage::class, 'product_color_variant_id')
                    ->where('type', 'gallery')
                    ->orderBy('sort_order');
    }

    public function thumbnailImage()
    {
        return $this->hasOne(ProductColorImage::class, 'product_color_variant_id')
                    ->where('type', 'thumbnail');
    }

    // Color → its sizes (S, M, L, XL...) each with stock + price
    public function sizeStocks()
    {
        return $this->hasMany(ProductSizeStock::class, 'product_color_variant_id');
    }
}