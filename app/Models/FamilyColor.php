<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FamilyColor extends Model
{
    protected $fillable = ['name', 'code', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function children()
    {
        return $this->hasMany(FamilyColorChild::class);
    }
}
