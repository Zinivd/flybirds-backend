<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Spotlight extends Model
{
    protected $fillable = [
        'title',
        'product_id',
        'image',
        'is_published',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'is_active'    => 'boolean',
        'sort_order'   => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
