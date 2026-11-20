<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourtReservation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['round' => 'integer', 'position' => 'integer', 'queue_position' => 'integer', 'version' => 'integer'];
    }
}
