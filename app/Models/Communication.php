<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Communication extends Model
{
    protected $guarded = [];
    protected $casts = ['blocks' => 'array', 'audience' => 'array', 'published_at' => 'datetime'];
}
