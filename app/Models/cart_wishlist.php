<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class cart_wishlist extends Model
{
    protected $table = 'cart_wishlists';

    protected $fillable = [
        'user_id',
        'product_id',
        'product_color_variant_id',
        'product_size_stock_id',
        'type',
        'quantity',
    ];

    public function user()
    {
        return $this->belongsTo(FlyUser::class, 'user_id', 'user_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function colorVariant()
    {
        return $this->belongsTo(ProductColorVariant::class, 'product_color_variant_id');
    }

    public function sizeStock()
    {
        return $this->belongsTo(ProductSizeStock::class, 'product_size_stock_id');
    }
}