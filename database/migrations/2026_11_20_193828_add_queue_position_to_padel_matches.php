<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('padel_matches', function (Blueprint $table): void {
            $table->unsignedInteger('queue_position')->nullable();
            $table->index(['court_id', 'status', 'queue_position']);
        });
        foreach (DB::table('courts')->pluck('id') as $courtId) {
            $position = 1;
            foreach (DB::table('padel_matches')->where('court_id', $courtId)->orderBy('id')->pluck('id') as $matchId) {
                DB::table('padel_matches')->where('id', $matchId)->update(['queue_position' => $position++]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('padel_matches', function (Blueprint $table): void {
            $table->dropIndex(['court_id', 'status', 'queue_position']);
            $table->dropColumn('queue_position');
        });
    }
};
