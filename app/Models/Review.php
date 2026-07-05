<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    protected $fillable = [
        'customer_id',
        'product_id',
        'rating',
        'comment',
    ];

    /**
     * Get the customer that wrote the review.
     */
    public function customer()
    {
        return $this->belongsTo(FlyUser::class, 'customer_id', 'user_id');
    }

    /**
     * Get the product that the review belongs to.
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
