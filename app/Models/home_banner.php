<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class home_banner extends Model
{
    use HasFactory;

    /**
     * Table name (Eloquent's default guess would be "home_banners" already,
     * since Laravel pluralizes + snake_cases the class name — but explicit is safer)
     */
    protected $table = 'home_banners';

    protected $fillable = [
        'title',
        'web_banner_path',
        'mobile_banner_path',
        'order_level',
        'status',
    ];

    protected $casts = [
        'status'      => 'boolean',
        'order_level' => 'integer',
    ];

    protected $appends = [
        'web_banner_url',
        'mobile_banner_url',
    ];

    public function getWebBannerUrlAttribute(): ?string
    {
        return $this->web_banner_path
            ? Storage::disk('s3')->url($this->web_banner_path)
            : null;
    }

    public function getMobileBannerUrlAttribute(): ?string
    {
        return $this->mobile_banner_path
            ? Storage::disk('s3')->url($this->mobile_banner_path)
            : null;
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order_level', 'asc')->orderBy('id', 'desc');
    }

    /**
     * Auto-delete S3 files when a record is deleted
     */
    protected static function booted()
    {
        static::deleting(function (home_banner $banner) {
            if ($banner->web_banner_path) {
                Storage::disk('s3')->delete($banner->web_banner_path);
            }
            if ($banner->mobile_banner_path) {
                Storage::disk('s3')->delete($banner->mobile_banner_path);
            }
        });
    }
}