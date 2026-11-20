<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Court extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['venue_id' => 'integer'];
    }
}
