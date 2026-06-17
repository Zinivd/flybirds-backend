<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    // Add these fields to the fillable array
    protected $fillable = ['file_name', 'file_size', 'file_url', 'mime_type'];
}