<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoReel extends Model
{
    protected $fillable = [
        'title',
        'description',
        'file_name',
        'file_size',
        'video_url',
        'file_type',
        'is_published',
        'product_id',
        'product_name',
    ];

    protected $casts = [
        'is_published' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
