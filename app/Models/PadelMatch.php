<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PadelMatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['rules' => 'array', 'state' => 'array', 'queue_position' => 'integer', 'version' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function scopeQueueOrder(Builder $query): Builder
    {
        return $query->orderBy('queue_position')->orderBy('id');
    }
}
