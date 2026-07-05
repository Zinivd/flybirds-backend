<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSupportQuery extends Model
{
    protected $table = 'product_support_queries';

    protected $fillable = [
        'product_id',
        'product_name',
        'user_id',
        'user_name',
        'question',
        'reply',
        'status',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function user()
    {
        return $this->belongsTo(FlyUser::class, 'user_id', 'user_id');
    }
}
