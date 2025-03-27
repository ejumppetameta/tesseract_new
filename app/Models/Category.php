<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = ['name', 'keywords'];

    // Automatically cast the keywords attribute from JSON to array
    protected $casts = [
        'keywords' => 'array',
    ];
}
