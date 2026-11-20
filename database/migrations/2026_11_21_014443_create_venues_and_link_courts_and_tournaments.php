<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150)->unique();
            $table->timestamps();
        });
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE courts ADD COLUMN venue_id INTEGER REFERENCES venues(id) ON DELETE RESTRICT');
            DB::statement('ALTER TABLE tournaments ADD COLUMN venue_id INTEGER REFERENCES venues(id) ON DELETE RESTRICT');
        } else {
            Schema::table('courts', fn (Blueprint $table) => $table->foreignId('venue_id')->nullable()->constrained()->restrictOnDelete());
            Schema::table('tournaments', fn (Blueprint $table) => $table->foreignId('venue_id')->nullable()->constrained()->restrictOnDelete());
        }
        $default = DB::table('venues')->insertGetId(['name' => 'Venue Utama', 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('tournaments')->orderBy('id')->get() as $tournament) {
            $name = preg_replace('/\s+/u', ' ', trim($tournament->venue ?? '')) ?: 'Venue Utama';
            $venueId = DB::table('venues')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->value('id')
                ?? DB::table('venues')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('tournaments')->where('id', $tournament->id)->update(['venue_id' => $venueId]);
        }
        $tournamentVenues = DB::table('tournaments')->distinct()->pluck('venue_id');
        foreach (DB::table('courts')->orderBy('id')->get() as $court) {
            $matches = DB::table('padel_matches')->join('tiers', 'tiers.id', '=', 'padel_matches.tier_id')->join('tournaments', 'tournaments.id', '=', 'tiers.tournament_id')->where('padel_matches.court_id', $court->id)->select('padel_matches.id', 'padel_matches.status', 'tournaments.venue_id')->get();
            $active = $matches->first(fn (object $match): bool => in_array($match->status, ['live', 'paused', 'review'], true));
            $primary = $active?->venue_id ?? $matches->first()?->venue_id ?? ($tournamentVenues->count() === 1 ? $tournamentVenues->first() : $default);
            DB::table('courts')->where('id', $court->id)->update(['venue_id' => $primary]);
            foreach ($matches->groupBy('venue_id') as $venueId => $venueMatches) {
                if ((int) $venueId === (int) $primary) {
                    continue;
                }
                $copy = DB::table('courts')->insertGetId(['name' => $court->name, 'venue_id' => $venueId, 'referee_id' => null, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('padel_matches')->whereIn('id', $venueMatches->pluck('id'))->update(['court_id' => $copy, 'version' => DB::raw('version + 1')]);
            }
        }
        Schema::table('courts', fn (Blueprint $table) => $table->unique(['venue_id', 'name']));
    }

    public function down(): void
    {
        Schema::table('courts', fn (Blueprint $table) => $table->dropUnique(['venue_id', 'name']));
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE courts DROP COLUMN venue_id');
            DB::statement('ALTER TABLE tournaments DROP COLUMN venue_id');
        } else {
            Schema::table('courts', fn (Blueprint $table) => $table->dropConstrainedForeignId('venue_id'));
            Schema::table('tournaments', fn (Blueprint $table) => $table->dropConstrainedForeignId('venue_id'));
        }
        Schema::dropIfExists('venues');
    }
};
