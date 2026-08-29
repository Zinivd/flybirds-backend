<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FamilyColorChild extends Model
{
    protected $fillable = ['family_color_id', 'name', 'code', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function familyColor()
    {
        return $this->belongsTo(FamilyColor::class);
    }
}
