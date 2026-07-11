<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'order_table_id',
        'product_id',
        'product_color_variant_id',
        'product_size_stock_id',
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

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function sizeStock()
    {
        return $this->belongsTo(ProductSizeStock::class, 'product_size_stock_id');
    }

    public function productSizeStock()
    {
        return $this->belongsTo(ProductSizeStock::class, 'product_size_stock_id');
    }

    public function productColorVariant()
    {
        return $this->belongsTo(ProductColorVariant::class, 'product_color_variant_id');
    }

    
}