<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProductColorVariant extends Model
{
    protected $fillable = [
        'product_id',
        'color_id',            // deprecated, kept for backward compatibility
        'family_color_id',
        'family_color_child_id',
    ];
    // ─── Relationships ─────────────────────────────────────────────
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    public function color()
    {
        return $this->belongsTo(Color::class); // deprecated
    }
    public function familyColor()
    {
        return $this->belongsTo(FamilyColor::class);
    }
    public function familyColorChild()
    {
        return $this->belongsTo(FamilyColorChild::class);
    }
    public function images()
    {
        return $this->hasMany(ProductColorImage::class, 'product_color_variant_id')
                    ->orderBy('sort_order');
    }
    public function galleryImages()
    {
        return $this->hasMany(ProductColorImage::class, 'product_color_variant_id')
                    ->where('type', 'gallery')
                    ->orderBy('sort_order');
    }
    public function thumbnailImage()
    {
        return $this->hasOne(ProductColorImage::class, 'product_color_variant_id')
                    ->where('type', 'thumbnail');
    }
    public function sizeStocks()
    {
        return $this->hasMany(ProductSizeStock::class, 'product_color_variant_id');
    }
}
