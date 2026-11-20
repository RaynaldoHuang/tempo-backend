<?php

namespace Database\Seeders;

use App\Models\Court;
use App\Models\ScoringPreset;
use App\Services\ScoringEngine;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            Court::firstOrCreate(['name' => "Court {$i}"]);
        }
        ScoringPreset::firstOrCreate(['name' => '2 Set • 6 Game • Super Tie-break 10'], ['rules' => ScoringEngine::defaults()]);
    }
}
