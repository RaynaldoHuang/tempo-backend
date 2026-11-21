<?php

namespace Tests\Feature;

use App\Models\Court;
use App\Models\PadelMatch;
use App\Models\Tier;
use App\Models\Tournament;
use App\Models\User;
use App\Models\Venue;
use App\Services\ScoringEngine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TournamentReportTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'admin'): void
    {
        $token = Str::random(40);
        User::factory()->create(['role' => $role, 'api_token' => hash('sha256', $token), 'token_expires_at' => now()->addDay()]);
        $this->withToken($token);
    }

    private function tier(string $name = 'Bronze Man'): Tier
    {
        $venue = Venue::firstOrCreate(['name' => 'Venue Utama']);
        $tournament = Tournament::create(['name' => 'Lions Open '.$name, 'venue_id' => $venue->id]);

        return Tier::create(['name' => $name, 'tournament_id' => $tournament->id, 'format' => 'group', 'groups' => [['name' => 'Grup A', 'teams' => ['A1', 'A2']], ['name' => 'Grup B', 'teams' => ['B1', 'B2']]], 'rules' => ScoringEngine::defaults()]);
    }

    private function match(Tier $tier, string $status, ?string $timestamp = null): PadelMatch
    {
        $state = (new ScoringEngine)->initial();
        if (in_array($status, ['finished', 'review'], true)) {
            $state['winner'] = 0;
            $state['sets'] = [['games' => [6, 4], 'kind' => 'regular', 'winner' => 0, 'tiebreak' => null]];
        }

        return PadelMatch::create(['tier_id' => $tier->id, 'stage' => 'group', 'group_name' => 'Grup A', 'team_a' => 'A1', 'team_b' => 'A2', 'status' => $status, 'state' => $state, 'rules' => $tier->rules, 'started_at' => $timestamp, 'finished_at' => $status === 'finished' ? $timestamp : null]);
    }

    public function test_report_requires_admin_and_does_not_expose_account_details(): void
    {
        $this->getJson('/api/v1/reports/tournament')->assertUnauthorized();
        $this->account('referee');
        $this->getJson('/api/v1/reports/tournament')->assertForbidden();
        $this->account();
        $response = $this->getJson('/api/v1/reports/tournament')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringNotContainsString('api_token', $response->getContent());
        $this->assertStringNotContainsString('password', $response->getContent());
    }

    public function test_all_time_report_includes_scheduled_and_review_without_confirming_anything(): void
    {
        $this->account();
        $tier = $this->tier();
        $finished = $this->match($tier, 'finished', '2026-11-21 10:00:00');
        $this->match($tier, 'review', '2026-11-22 01:00:00');
        $this->match($tier, 'scheduled');
        $before = [];
        foreach (['tiers', 'padel_matches', 'score_events', 'court_reservations'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $response = $this->getJson('/api/v1/reports/tournament')->assertOk()
            ->assertJsonPath('period', 'all')->assertJsonPath('timezone', 'Asia/Jakarta')
            ->assertJsonPath('summary.total', 3)->assertJsonPath('summary.finished', 1)->assertJsonPath('summary.review', 1)->assertJsonPath('summary.scheduled', 1)
            ->assertJsonPath('categories.0.matches.0.id', $finished->id)->assertJsonPath('categories.0.matches.0.winner', 'A1')
            ->assertJsonPath('categories.0.matches.1.winner', null)->assertJsonPath('categories.0.standings.groups.0.rows.0.wins', 1)
            ->assertJsonPath('categories.0.bracket_confirmed', false)->assertJsonPath('categories.0.champion', null);
        $this->assertCount(3, $response->json('categories.0.matches'));
        foreach ($before as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->get()->toJson());
        }
    }

    public function test_today_uses_wib_boundaries_and_finish_time_instead_of_start_time(): void
    {
        $stored = fn (string $iso): string => CarbonImmutable::parse($iso)->setTimezone(date_default_timezone_get())->toDateTimeString();
        $this->travelTo(CarbonImmutable::parse('2026-11-22T05:00:00Z'));
        $this->account();
        $tier = $this->tier();
        $this->match($tier, 'finished', $stored('2026-11-21T16:59:59Z'));
        $firstToday = $this->match($tier, 'finished', $stored('2026-11-21T17:00:00Z'));
        $firstToday->update(['started_at' => $stored('2026-11-21T16:30:00Z')]);
        $live = $this->match($tier, 'live', $stored('2026-11-22T04:00:00Z'));
        $this->match($tier, 'scheduled');
        $this->match($tier, 'live', $stored('2026-11-22T05:01:00Z'));
        $this->getJson('/api/v1/reports/tournament?period=today')->assertOk()->assertJsonPath('start_date', '2026-11-22')->assertJsonCount(2, 'categories.0.matches')
            ->assertJsonPath('categories.0.matches.0.id', $firstToday->id)->assertJsonPath('categories.0.matches.1.id', $live->id);
        $this->getJson('/api/v1/reports/tournament?period=range&start_date=2026-11-21&end_date=2026-11-21')->assertOk()->assertJsonCount(1, 'categories.0.matches');
    }

    public function test_tournament_category_and_section_filters_are_validated(): void
    {
        $this->account();
        $first = $this->tier();
        $second = $this->tier('Silver Men');
        $this->match($first, 'scheduled');
        $this->match($second, 'scheduled');
        $this->getJson('/api/v1/reports/tournament?tournament_id='.$first->tournament_id)->assertOk()->assertJsonCount(1, 'categories')->assertJsonPath('categories.0.id', $first->id);
        $this->getJson('/api/v1/reports/tournament?tier_id='.$second->id.'&results=0&standings=0')->assertOk()->assertJsonPath('sections.results', false)->assertJsonCount(0, 'categories.0.matches')->assertJsonPath('categories.0.standings', null);
        $this->getJson('/api/v1/reports/tournament?tournament_id='.$first->tournament_id.'&tier_id='.$second->id)->assertUnprocessable();
        foreach (['results=0&standings=0&bracket=0', 'period=range', 'period=range&start_date=2026-11-20&end_date=2026-11-19', 'period=unknown', 'tier_id=999999'] as $query) {
            $this->getJson('/api/v1/reports/tournament?'.$query)->assertUnprocessable();
        }
    }

    public function test_saved_corrected_bracket_byes_and_champion_are_preserved(): void
    {
        $this->account();
        $tier = $this->tier('Women');
        $court = Court::create(['name' => 'Court 1', 'venue_id' => Tournament::findOrFail($tier->tournament_id)->venue_id]);
        $final = $this->match($tier, 'review', '2026-11-22 02:00:00');
        $final->update(['stage' => 'knockout', 'team_a' => 'Ipeh / Maya', 'team_b' => 'Anggi / Dita', 'court_id' => $court->id]);
        $node = ['a' => ['label' => 'Pemenang R1 · 2', 'team' => $final->team_a], 'b' => ['label' => 'Pemenang R1 · 1', 'team' => $final->team_b], 'match_id' => $final->id, 'winner' => null, 'bye' => false];
        $bye = ['a' => ['label' => 'Slot 1', 'team' => 'Anggi / Dita'], 'b' => ['label' => 'BYE', 'team' => null], 'match_id' => null, 'winner' => 'Anggi / Dita', 'bye' => true];
        $tier->update(['format' => 'knockout', 'knockout' => ['draw_mode' => 'manual', 'confirmed_at' => now()->toIso8601String(), 'rounds' => [['name' => 'Semifinal', 'matches' => [$bye]], ['name' => 'Final', 'matches' => [$node]]]]]);
        $response = $this->getJson('/api/v1/reports/tournament?tier_id='.$tier->id)->assertOk()->assertJsonPath('categories.0.champion', null)->assertJsonPath('categories.0.bracket.0.matches.0.bye', true)
            ->assertJsonPath('categories.0.bracket.1.matches.0.a.team', 'Ipeh / Maya')->assertJsonPath('categories.0.bracket.1.matches.0.a.label', 'Pemenang R1 · 2')
            ->assertJsonPath('categories.0.bracket.1.matches.0.match.court_name', 'Court 1');
        $this->assertNull($response->json('categories.0.standings'));
        $final->update(['status' => 'finished', 'finished_at' => now()]);
        $config = $tier->knockout;
        $config['rounds'][1]['matches'][0]['winner'] = 'Ipeh / Maya';
        $tier->update(['knockout' => $config]);
        $this->getJson('/api/v1/reports/tournament?tier_id='.$tier->id)->assertOk()->assertJsonPath('categories.0.champion', 'Ipeh / Maya');
    }
}
