<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('court_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('court_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('round');
            $table->unsignedInteger('position');
            $table->unsignedInteger('queue_position');
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->unique(['tier_id', 'round', 'position']);
            $table->index(['court_id', 'queue_position']);
        });
        foreach (DB::table('tiers')->whereNotNull('knockout')->orderBy('id')->get() as $tier) {
            $config = json_decode($tier->knockout, true);
            $venueId = DB::table('tournaments')->where('id', $tier->tournament_id)->value('venue_id');
            foreach ($config['schedule'] ?? [] as $key => $courtId) {
                [$round, $position] = array_map('intval', explode(':', $key));
                $node = $config['rounds'][$round]['matches'][$position] ?? [];
                if (! $courtId || ($node['match_id'] ?? null) || ($node['bye'] ?? false) || ! DB::table('courts')->where('id', $courtId)->where('venue_id', $venueId)->exists()) {
                    continue;
                }
                $queuePosition = max(DB::table('padel_matches')->where('court_id', $courtId)->max('queue_position') ?? 0, DB::table('court_reservations')->where('court_id', $courtId)->max('queue_position') ?? 0) + 1;
                DB::table('court_reservations')->insert(['tier_id' => $tier->id, 'court_id' => $courtId, 'round' => $round, 'position' => $position, 'queue_position' => $queuePosition, 'version' => 0, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('court_reservations');
    }
};
