<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = ['name', 'type', 'parent_id', 'order_level', 'banner_path', 'icon_path', 'cover_path'];

    // Get the parent category
    public function parent() {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    // Get child categories
    public function children() {
        return $this->hasMany(Category::class, 'parent_id');
    }
}