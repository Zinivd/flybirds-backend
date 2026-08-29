<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Product extends Model
{
    protected $fillable = [
        'name', 'brand', 'unit', 'weight', 'min_qty', 'tags',
        'estimate_shipping_days', 'description', 'category_id',
        'unit_price', 'discount', 'discount_type',
        'discount_start_date', 'discount_end_date', 'reward_points',
        'is_flash_sale', 'flash_sale_title', 'flash_sale_discount', 'flash_sale_discount_type',
        'is_today_sale', 'is_published', 'is_active',
        'spotlight_image', 'seo_title', 'seo_description', 'seo_keywords',
    ];
    protected $casts = [
        'is_flash_sale'       => 'boolean',
        'is_today_sale'       => 'boolean',
        'is_published'        => 'boolean',
        'is_active'           => 'boolean',
        'discount_start_date' => 'date',
        'discount_end_date'   => 'date',
        'seo_keywords'        => 'array',
    ];
    protected $appends = ['effective_price'];
    // ─── Relationships ─────────────────────────────────────────────
    public function category()
    {
        return $this->belongsTo(Category::class);
    }
    public function colorVariants()
    {
        return $this->hasMany(ProductColorVariant::class);
    }
    public function reviews()
    {
        return $this->hasMany(Review::class);
    }
    // ─── Computed Price ────────────────────────────────────────────
    public function getEffectivePriceAttribute(): float
    {
        $price = (float) $this->unit_price;
        if ($this->is_flash_sale && $this->flash_sale_discount > 0) {
            if ($this->flash_sale_discount_type === 'percent') {
                return round($price - ($price * $this->flash_sale_discount / 100), 2);
            }
            return round(max(0, $price - $this->flash_sale_discount), 2);
        }
        if ($this->discount > 0) {
            $now = now()->toDateString();
            $inRange = (
                (!$this->discount_start_date && !$this->discount_end_date) ||
                ($this->discount_start_date && $this->discount_end_date &&
                 $now >= $this->discount_start_date->toDateString() &&
                 $now <= $this->discount_end_date->toDateString())
            );
            if ($inRange) {
                if ($this->discount_type === 'percent') {
                    return round($price - ($price * $this->discount / 100), 2);
                }
                return round(max(0, $price - $this->discount), 2);
            }
        }
        return $price;
    }
}
