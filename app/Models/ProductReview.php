<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ═══════════════════════════════════════════════════════════════
 * Create with:
 *   php artisan make:model ProductReview
 * Then replace the generated file's contents with this.
 * ═══════════════════════════════════════════════════════════════
 *
 * @property int $id
 * @property string $user_id
 * @property int $product_id
 * @property string $title
 * @property string|null $description
 * @property int $rating
 */
class ProductReview extends Model
{
    use HasFactory;

    protected $table = 'product_reviews';

    protected $fillable = [
        'user_id',
        'product_id',
        'title',
        'description',
        'rating',
    ];

    protected $casts = [
        'rating'     => 'integer',
        'product_id' => 'integer',
    ];

    // ── Relationships ───────────────────────────────────────────

    /**
     * The customer who wrote this review.
     * Adjust the related model/keys if your User model differs
     * (e.g. App\Models\User mapped to the fly_users table with
     * primary key 'user_id').
     */
public function user()
{
    return $this->belongsTo(FlyUser::class, 'user_id', 'user_id');
}

    /**
     * The product this review is for.
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    
}