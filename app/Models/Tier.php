<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tier extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['rules' => 'array', 'groups' => 'array', 'knockout' => 'array'];
    }
}
