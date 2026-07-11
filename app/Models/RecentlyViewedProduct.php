<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecentlyViewedProduct extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
        'category_id',
        'product_name',
        'category_name',
        'viewed_at',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}