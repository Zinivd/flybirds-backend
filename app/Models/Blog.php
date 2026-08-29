<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Blog extends Model
{
    protected $fillable = [
        'title',
        'sub_title',
        'description_1',
        'description_2',
        'description_3',
        'cover_image_path',
        'product_id',
        'is_published',
        'published_at',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        // adjust related model name/table if different in your project
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderByDesc('created_at');
    }
}
