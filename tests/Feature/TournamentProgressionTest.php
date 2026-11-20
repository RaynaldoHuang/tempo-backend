<?php

namespace Tests\Feature;

use App\Models\Court;
use App\Models\CourtReservation;
use App\Models\PadelMatch;
use App\Models\Tier;
use App\Models\Tournament;
use App\Models\User;
use App\Models\Venue;
use App\Services\ScoringEngine;
use App\Services\TournamentProgression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TournamentProgressionTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'admin'): void
    {
        $token = Str::random(40);
        User::factory()->create(['role' => $role, 'api_token' => hash('sha256', $token), 'token_expires_at' => now()->addDay()]);
        $this->withToken($token);
    }

    private function tier(int $count = 2, bool $finished = true): Tier
    {
        $groups = [];
        for ($i = 0; $i < $count; $i++) {
            $name = chr(65 + $i);
            $groups[] = ['name' => "Grup {$name}", 'teams' => ["{$name}1", "{$name}2"]];
        }
        $tier = Tier::create(['tournament_id' => Tournament::create(['venue_id' => Venue::firstOrCreate(['name' => 'Venue Utama'])->id, 'name' => 'Knockout Open'])->id, 'name' => 'Bronze', 'format' => 'group', 'groups' => $groups, 'rules' => [...ScoringEngine::defaults(), 'regular_sets' => 1]]);
        foreach ($groups as $group) {
            $this->game($tier, $group['name'], $group['teams'][0], $group['teams'][1], 0, $finished ? 'finished' : 'scheduled');
        }

        return $tier;
    }

    private function game(Tier $tier, string $group, string $a, string $b, int $winner = 0, string $status = 'finished'): PadelMatch
    {
        $state = (new ScoringEngine)->initial();
        $state['winner'] = $winner;
        $state['sets'] = [['games' => $winner === 0 ? [6, 0] : [0, 6], 'kind' => 'regular', 'winner' => $winner]];

        return PadelMatch::create(['tier_id' => $tier->id, 'stage' => 'group', 'group_name' => $group, 'team_a' => $a, 'team_b' => $b, 'status' => $status, 'state' => $state, 'rules' => $tier->rules]);
    }

    private function confirm(PadelMatch $match, int $winner = 0): void
    {
        $state = $match->state;
        $state['winner'] = $winner;
        $state['sets'] = [['games' => $winner === 0 ? [6, 0] : [0, 6], 'kind' => 'regular', 'winner' => $winner]];
        $match->update(['status' => 'review', 'state' => $state]);
        $this->postJson("/api/v1/matches/{$match->id}/score", ['action' => 'confirm', 'version' => $match->version, 'command_id' => (string) Str::uuid()])->assertOk();
    }

    public function test_reserved_knockout_slot_joins_queue_immediately_and_reorders_with_group_matches(): void
    {
        $this->account();
        $tier = $this->tier(2, false);
        $court = Court::create(['name' => 'Court 1', 'venue_id' => Tournament::find($tier->tournament_id)->venue_id]);
        $groups = PadelMatch::where('tier_id', $tier->id)->get();
        $this->postJson("/api/v1/matches/{$groups[0]->id}/assign", ['court_id' => $court->id])->assertOk()->assertJsonPath('queue_position', 1);
        $payload = ['round' => 0, 'position' => 0, 'revision' => (new TournamentProgression)->scheduleRevision($tier), 'court_id' => $court->id];
        $this->postJson("/api/v1/tiers/{$tier->id}/knockout-schedule", $payload)->assertOk();
        $reservation = CourtReservation::firstOrFail();
        $this->assertSame(2, $reservation->queue_position);
        $this->postJson("/api/v1/tiers/{$tier->id}/knockout-schedule", $payload)->assertOk();
        $this->assertSame(2, $reservation->fresh()->queue_position);
        $this->postJson("/api/v1/matches/{$groups[1]->id}/assign", ['court_id' => $court->id])->assertOk()->assertJsonPath('queue_position', 3);
        $this->postJson("/api/v1/court-reservations/{$reservation->id}/reorder", ['direction' => 'down', 'version' => 0])->assertOk()->assertJsonPath('queue_position', 3);
        $this->assertSame(2, $groups[1]->fresh()->queue_position);
        $this->postJson("/api/v1/court-reservations/{$reservation->id}/reorder", ['direction' => 'up', 'version' => 0])->assertConflict();
        $this->postJson("/api/v1/matches/{$groups[1]->id}/reorder", ['direction' => 'down', 'version' => $groups[1]->fresh()->version])->assertOk()->assertJsonPath('queue_position', 3);
        PadelMatch::where('tier_id', $tier->id)->update(['status' => 'finished']);
        $this->postJson("/api/v1/tiers/{$tier->id}/knockout", ['revision' => (new TournamentProgression)->overview($tier->fresh())['revision']])->assertOk();
        $match = PadelMatch::where('stage', 'knockout')->orderBy('id')->first();
        $this->assertSame(2, $match->queue_position);
        $this->assertSame($court->id, $match->court_id);
        $this->assertDatabaseCount('court_reservations', 0);
    }

    public function test_reserved_queue_head_is_visible_on_tv_and_blocks_start_until_reordered(): void
    {
        $this->account();
        $tier = $this->tier(2, false);
        $court = Court::create(['name' => 'Court 1', 'venue_id' => Tournament::find($tier->tournament_id)->venue_id]);
        $this->postJson("/api/v1/tiers/{$tier->id}/knockout-schedule", ['round' => 0, 'position' => 0, 'revision' => (new TournamentProgression)->scheduleRevision($tier), 'court_id' => $court->id])->assertOk();
        $reservation = CourtReservation::firstOrFail();
        $match = PadelMatch::where('tier_id', $tier->id)->first();
        $this->postJson("/api/v1/matches/{$match->id}/assign", ['court_id' => $court->id])->assertOk()->assertJsonPath('queue_position', 2);
        $this->getJson('/api/v1/board')->assertOk()->assertJsonPath('courts.0.match.status', 'waiting')->assertJsonPath('courts.0.match.team_a', 'Juara Grup A')->assertJsonPath('courts.0.next_match.id', $match->id);
        $start = fn (): array => ['action' => 'start', 'server' => 0, 'version' => $match->fresh()->version, 'command_id' => (string) Str::uuid()];
        $this->postJson("/api/v1/matches/{$match->id}/score", $start())->assertConflict();
        $this->postJson("/api/v1/matches/{$match->id}/reorder", ['direction' => 'up', 'version' => $match->fresh()->version])->assertOk();
        $this->postJson("/api/v1/matches/{$match->id}/score", $start())->assertOk();
        $this->getJson('/api/v1/board')->assertOk()->assertJsonPath('courts.0.match.id', $match->id)->assertJsonPath('courts.0.next_match.reservation_id', $reservation->id);
        $this->account('referee');
        $court->update(['referee_id' => User::where('role', 'referee')->latest('id')->first()->id]);
        $this->getJson('/api/v1/data')->assertOk()->assertJsonCount(1, 'knockout_schedule')->assertJsonPath('knockout_schedule.0.reservation_id', $reservation->id);
        $this->postJson("/api/v1/court-reservations/{$reservation->id}/reorder", ['direction' => 'up', 'version' => $reservation->fresh()->version])->assertForbidden();
    }

    public function test_moving_or_removing_reserved_court_updates_queue_without_duplicate_slots(): void
    {
        $this->account();
        $tier = $this->tier(2, false);
        $venueId = Tournament::find($tier->tournament_id)->venue_id;
        $first = Court::create(['name' => 'Court 1', 'venue_id' => $venueId]);
        $second = Court::create(['name' => 'Court 2', 'venue_id' => $venueId]);
        $url = "/api/v1/tiers/{$tier->id}/knockout-schedule";
        $payload = ['round' => 0, 'position' => 0, 'revision' => (new TournamentProgression)->scheduleRevision($tier), 'court_id' => $first->id];
        $this->postJson($url, $payload)->assertOk();
        $this->postJson($url, [...$payload, 'court_id' => $second->id])->assertOk();
        $this->assertDatabaseCount('court_reservations', 1);
        $this->assertDatabaseHas('court_reservations', ['court_id' => $second->id, 'queue_position' => 1]);
        $this->postJson($url, [...$payload, 'court_id' => null])->assertOk();
        $this->assertDatabaseCount('court_reservations', 0);
        $this->getJson('/api/v1/data')->assertJsonPath('knockout_schedule.0.court_id', null)->assertJsonPath('knockout_schedule.0.queue_position', null);
    }

    public function test_existing_court_choices_are_backfilled_into_queue_by_migration(): void
    {
        $tier = $this->tier(2, false);
        $court = Court::create(['name' => 'Court 1', 'venue_id' => Tournament::find($tier->tournament_id)->venue_id]);
        $tier->update(['knockout' => ['schedule' => ['0:0' => $court->id, '1:0' => $court->id]]]);
        $migration = require database_path('migrations/2026_11_21_040738_create_court_reservations_table.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseCount('court_reservations', 2);
        $this->assertSame([1, 2], CourtReservation::orderBy('queue_position')->pluck('queue_position')->all());
        $this->assertSame($court->id, $tier->fresh()->knockout['schedule']['0:0']);
    }

    public function test_future_knockout_courts_are_saved_before_groups_finish_and_carried_into_each_round(): void
    {
        $this->account();
        $tier = $this->tier(2, false);
        $court = Court::create(['name' => 'Court 1', 'venue_id' => Tournament::find($tier->tournament_id)->venue_id]);
        $url = "/api/v1/tiers/{$tier->id}";
        $revision = (new TournamentProgression)->scheduleRevision($tier);
        $this->getJson('/api/v1/data')->assertOk()->assertJsonCount(3, 'knockout_schedule')->assertJsonPath('knockout_schedule.0.team_a', null)->assertJsonPath('knockout_schedule.0.label_a', 'Juara Grup A')->assertJsonPath('knockout_schedule.2.label_a', 'Pemenang Semifinal 1');
        foreach ([[0, 0], [1, 0]] as [$round, $position]) {
            $this->postJson("{$url}/knockout-schedule", compact('round', 'position', 'revision') + ['court_id' => $court->id])->assertOk();
        }
        $this->assertSame(0, PadelMatch::where('stage', 'knockout')->count());
        $this->postJson("{$url}/progression", ['qualifiers' => 2])->assertOk();
        $this->assertSame($court->id, $tier->fresh()->knockout['schedule']['0:0']);
        $this->getJson('/api/v1/data')->assertJsonPath('knockout_schedule.0.court_id', $court->id);
        PadelMatch::where('tier_id', $tier->id)->update(['status' => 'finished']);
        $this->postJson("{$url}/knockout", ['revision' => (new TournamentProgression)->overview($tier->fresh())['revision']])->assertOk();
        $matches = PadelMatch::where('stage', 'knockout')->orderBy('id')->get();
        $this->assertSame($court->id, $matches[0]->court_id);
        $this->assertSame(1, $matches[0]->queue_position);
        $this->getJson('/api/v1/data')->assertJsonCount(1, 'knockout_schedule');
        $this->postJson("{$url}/knockout-schedule", ['round' => 0, 'position' => 0, 'revision' => $revision, 'court_id' => $court->id])->assertConflict();
        $this->confirm($matches[0]);
        $this->confirm($matches[1]);
        $final = PadelMatch::where('stage', 'knockout')->latest('id')->first();
        $this->assertSame('Final · 1', $final->group_name);
        $this->assertSame($court->id, $final->court_id);
        $this->assertSame(2, $final->queue_position);
        $this->getJson('/api/v1/data')->assertJsonCount(0, 'knockout_schedule');
    }

    public function test_future_knockout_schedule_checks_permissions_venue_byes_and_changed_draw(): void
    {
        $tier = $this->tier(3, false);
        $court = Court::create(['name' => 'Court 1', 'venue_id' => Tournament::find($tier->tournament_id)->venue_id]);
        $other = Court::create(['name' => 'Other court', 'venue_id' => Venue::create(['name' => 'Other venue'])->id]);
        $url = "/api/v1/tiers/{$tier->id}/knockout-schedule";
        $payload = ['round' => 0, 'position' => 0, 'revision' => (new TournamentProgression)->scheduleRevision($tier), 'court_id' => $court->id];
        $this->postJson($url, $payload)->assertUnauthorized();
        $this->account('referee');
        $this->postJson($url, $payload)->assertForbidden();
        $this->getJson('/api/v1/data')->assertJsonCount(0, 'knockout_schedule');
        $this->account();
        $this->postJson($url, [...$payload, 'court_id' => $other->id])->assertUnprocessable();
        $this->postJson($url, [...$payload, 'position' => 1])->assertConflict();
        $this->postJson($url, [...$payload, 'round' => 10])->assertConflict();
        $this->postJson($url, $payload)->assertOk();
        $this->postJson($url, [...$payload, 'court_id' => null])->assertOk();
        $this->assertNull($tier->fresh()->knockout['schedule']['0:0']);
        $this->postJson($url, $payload)->assertOk();
        $this->postJson("/api/v1/tiers/{$tier->id}/progression", ['qualifiers' => 1])->assertOk();
        $this->assertEmpty($tier->fresh()->knockout['schedule']);
        $this->postJson($url, $payload)->assertConflict();
    }

    public function test_direct_knockout_future_rounds_keep_planned_courts_after_confirmation(): void
    {
        $this->account();
        $tier = $this->directKnockout(4);
        $court = Court::create(['name' => 'Final court', 'venue_id' => Tournament::find($tier->tournament_id)->venue_id]);
        $this->getJson('/api/v1/data')->assertJsonCount(3, 'knockout_schedule');
        $this->postJson("/api/v1/tiers/{$tier->id}/knockout-schedule", ['round' => 1, 'position' => 0, 'revision' => (new TournamentProgression)->scheduleRevision($tier), 'court_id' => $court->id])->assertOk();
        $this->postJson("/api/v1/tiers/{$tier->id}/knockout", ['revision' => (new TournamentProgression)->overview($tier->fresh())['revision']])->assertOk();
        foreach (PadelMatch::where('tier_id', $tier->id)->get() as $match) {
            $this->confirm($match);
        }
        $final = PadelMatch::where('tier_id', $tier->id)->latest('id')->first();
        $this->assertSame('Final · 1', $final->group_name);
        $this->assertSame($court->id, $final->court_id);
        $this->getJson('/api/v1/data')->assertJsonCount(0, 'knockout_schedule');
    }

    public function test_deleted_planned_court_is_not_assigned_when_knockout_becomes_ready(): void
    {
        $this->account();
        $tier = $this->tier();
        $court = Court::create(['name' => 'Court 1', 'venue_id' => Tournament::find($tier->tournament_id)->venue_id]);
        $this->postJson("/api/v1/tiers/{$tier->id}/knockout-schedule", ['round' => 0, 'position' => 0, 'revision' => (new TournamentProgression)->scheduleRevision($tier), 'court_id' => $court->id])->assertOk();
        $this->deleteJson("/api/v1/courts/{$court->id}")->assertOk();
        $this->getJson('/api/v1/data')->assertJsonPath('knockout_schedule.0.court_id', null);
        $this->postJson("/api/v1/tiers/{$tier->id}/knockout", ['revision' => (new TournamentProgression)->overview($tier->fresh())['revision']])->assertOk();
        $this->assertNull(PadelMatch::where('stage', 'knockout')->first()->court_id);
    }

    public function test_cross_group_draw_advances_only_confirmed_results_and_produces_champion(): void
    {
        $this->account();
        $tier = $this->tier();
        $url = "/api/v1/tiers/{$tier->id}";
        $this->getJson("{$url}/progression")->assertOk()->assertJsonPath('ready', true)->assertJsonPath('rounds.0.matches.0.a.team', 'A1')->assertJsonPath('rounds.0.matches.0.b.team', 'B2');
        $this->postJson("{$url}/knockout", ['revision' => (new TournamentProgression)->overview($tier->fresh())['revision']])->assertOk();
        $matches = PadelMatch::where('stage', 'knockout')->orderBy('id')->get();
        $this->assertCount(2, $matches);
        $this->assertSame('A1', $matches[0]->team_a);
        $this->assertSame('B2', $matches[0]->team_b);
        $this->confirm($matches[0]);
        $this->assertSame(2, PadelMatch::where('stage', 'knockout')->count());
        $this->confirm($matches[1], 1);
        $final = PadelMatch::where('stage', 'knockout')->latest('id')->first();
        $this->assertSame('Final · 1', $final->group_name);
        $this->assertSame('A1', $final->team_a);
        $this->assertSame('A2', $final->team_b);
        $this->confirm($final, 1);
        $this->getJson("{$url}/progression")->assertJsonPath('rounds.1.matches.0.winner', 'A2');
        $this->postJson("{$url}/knockout", ['revision' => (new TournamentProgression)->overview($tier->fresh())['revision']])->assertConflict();
        $this->postJson("{$url}/progression", ['qualifiers' => 1])->assertConflict();
        $this->assertSame(3, PadelMatch::where('stage', 'knockout')->count());
        $source = PadelMatch::where('stage', 'group')->first();
        $this->postJson("/api/v1/matches/{$source->id}/score", ['action' => 'reopen', 'version' => $source->version, 'command_id' => (string) Str::uuid()])->assertConflict();
    }

    public function test_three_groups_use_byes_and_complete_without_placeholder_matches(): void
    {
        $this->account();
        $tier = $this->tier(3);
        $this->postJson("/api/v1/tiers/{$tier->id}/knockout", ['revision' => (new TournamentProgression)->overview($tier->fresh())['revision']])->assertOk();
        $rounds = $tier->fresh()->knockout['rounds'];
        $this->assertTrue($rounds[0]['matches'][1]['bye']);
        $this->assertTrue($rounds[0]['matches'][3]['bye']);
        $this->assertSame('C1', $rounds[0]['matches'][1]['winner']);
        $this->assertSame('C2', $rounds[0]['matches'][3]['winner']);
        while ($match = PadelMatch::where('stage', 'knockout')->where('status', 'scheduled')->first()) {
            $this->assertNotEmpty($match->team_a);
            $this->assertNotEmpty($match->team_b);
            $this->confirm($match);
        }
        $this->assertSame(5, PadelMatch::where('stage', 'knockout')->count());
        $this->assertNotNull($tier->fresh()->knockout['rounds'][2]['matches'][0]['winner']);
    }

    public function test_four_group_opponents_and_one_qualifier_configuration(): void
    {
        $this->account();
        $tier = $this->tier(4);
        $overview = (new TournamentProgression)->overview($tier);
        $pairs = array_map(fn (array $node): array => [$node['a']['team'], $node['b']['team']], $overview['rounds'][0]['matches']);
        $this->assertSame([['A1', 'B2'], ['C1', 'D2'], ['B1', 'A2'], ['D1', 'C2']], $pairs);
        $this->postJson("/api/v1/tiers/{$tier->id}/progression", ['qualifiers' => 1])->assertOk();
        $this->postJson("/api/v1/tiers/{$tier->id}/knockout", ['revision' => (new TournamentProgression)->overview($tier->fresh())['revision']])->assertOk();
        $this->assertSame(2, PadelMatch::where('stage', 'knockout')->count());
        $this->assertCount(2, $tier->fresh()->knockout['rounds']);
    }

    public function test_incomplete_groups_and_non_admin_cannot_generate_bracket(): void
    {
        $tier = $this->tier(2, false);
        $url = "/api/v1/tiers/{$tier->id}";
        $this->postJson("{$url}/knockout", ['revision' => (new TournamentProgression)->overview($tier->fresh())['revision']])->assertUnauthorized();
        $this->account('referee');
        $this->postJson("{$url}/knockout", ['revision' => (new TournamentProgression)->overview($tier->fresh())['revision']])->assertForbidden();
        $this->getJson("{$url}/progression")->assertForbidden();
        $this->postJson("{$url}/progression", ['qualifiers' => 2])->assertForbidden();
        $this->account();
        $this->postJson("{$url}/knockout", ['revision' => (new TournamentProgression)->overview($tier->fresh())['revision']])->assertConflict();
        $this->postJson("{$url}/progression", ['qualifiers' => 3])->assertUnprocessable();
        $this->assertDatabaseCount('padel_matches', 2);
    }

    public function test_three_way_tie_requires_explicit_order_and_rejects_invalid_order(): void
    {
        $this->account();
        $tier = $this->tier(1);
        PadelMatch::query()->delete();
        $tier->update(['groups' => [['name' => 'Grup A', 'teams' => ['A', 'B', 'C']]]]);
        $this->game($tier, 'Grup A', 'A', 'B');
        $this->game($tier, 'Grup A', 'B', 'C');
        $this->game($tier, 'Grup A', 'C', 'A');
        $url = "/api/v1/tiers/{$tier->id}";
        $this->getJson("{$url}/progression")->assertJsonPath('ready', false)->assertJsonPath('groups.0.unresolved', true);
        $this->postJson("{$url}/knockout", ['revision' => (new TournamentProgression)->overview($tier->fresh())['revision']])->assertConflict();
        $this->postJson("{$url}/progression", ['qualifiers' => 2, 'tie_orders' => ['Grup A' => ['A', 'A', 'B']]])->assertUnprocessable();
        $this->postJson("{$url}/progression", ['qualifiers' => 2, 'tie_orders' => ['Grup A' => ['C', 'B', 'A']]])->assertOk();
        $this->getJson("{$url}/progression")->assertJsonPath('ready', true)->assertJsonPath('groups.0.rows.0.team', 'C');
        $this->postJson("{$url}/knockout", ['revision' => (new TournamentProgression)->overview($tier->fresh())['revision']])->assertOk();
        $this->assertSame('C', PadelMatch::where('stage', 'knockout')->first()->team_a);
    }

    public function test_public_board_exposes_group_standings_without_admin_details(): void
    {
        $tier = $this->tier();
        for ($i = 1; $i <= 7; $i++) {
            Court::create(['venue_id' => Venue::firstOrCreate(['name' => 'Venue Utama'])->id, 'name' => "Court {$i}"]);
        }
        $this->getJson('/api/v1/board')->assertOk()->assertJsonCount(7, 'courts')->assertJsonCount(2, 'standings')->assertJsonPath('standings.0.group_name', 'Grup A')->assertJsonPath('standings.0.rows.0.team', 'A1')->assertJsonPath('standings.0.rows.0.wins', 1)->assertJsonPath('standings.0.rows.0.games', 6)->assertJsonPath('standings.0.confirmed', false)->assertJsonMissingPath('standings.0.tie_orders')->assertJsonMissingPath('standings.0.knockout');
        $this->account();
        $this->postJson("/api/v1/tiers/{$tier->id}/knockout", ['revision' => (new TournamentProgression)->overview($tier)['revision']])->assertOk();
        $this->confirm(PadelMatch::where('stage', 'knockout')->first());
        $this->getJson('/api/v1/board')->assertJsonCount(0, 'standings')->assertJsonCount(1, 'knockouts')->assertJsonPath('knockouts.0.round_name', 'Semifinal')->assertJsonPath('knockouts.0.entries.0.winner', 'A1')->assertJsonMissingPath('knockouts.0.knockout');
        Court::create(['venue_id' => Venue::firstOrCreate(['name' => 'Venue Utama'])->id, 'name' => 'Court 8']);
        $this->getJson('/api/v1/board')->assertJsonCount(8, 'courts')->assertJsonCount(0, 'standings')->assertJsonCount(0, 'knockouts');
    }

    private function directKnockout(int $size = 8, string $mode = 'ordered'): Tier
    {
        $tournament = Tournament::create(['venue_id' => Venue::firstOrCreate(['name' => 'Venue Utama'])->id, 'name' => 'Direct Open']);
        $teams = array_map(fn (int $index): string => "Team {$index}", range(1, $size));
        $response = $this->postJson('/api/v1/tiers', ['tournament_id' => $tournament->id, 'name' => 'Silver', 'format' => 'knockout', 'draw_mode' => $mode, 'rules' => [...ScoringEngine::defaults(), 'regular_sets' => 1], 'groups' => [['name' => 'Peserta knockout', 'teams' => $teams]]])->assertCreated();

        return Tier::findOrFail($response->json('id'));
    }

    public function test_direct_eight_team_knockout_has_stable_preview_and_advances_to_champion_on_tv(): void
    {
        $this->account();
        $groupTier = $this->tier();
        $tier = $this->directKnockout();
        $url = "/api/v1/tiers/{$tier->id}";
        $this->assertSame(0, PadelMatch::where('tier_id', $tier->id)->count());
        $preview = $this->getJson("{$url}/progression")->assertOk()->assertJsonPath('ready', true)->assertJsonCount(0, 'groups')->assertJsonPath('rounds.0.name', 'Perempat final')->assertJsonPath('rounds.0.matches.0.a.team', 'Team 1')->assertJsonPath('rounds.0.matches.0.b.team', 'Team 2')->json();
        $this->getJson("{$url}/progression")->assertJsonPath('revision', $preview['revision']);
        $this->getJson('/api/v1/board')->assertJsonCount(0, 'knockouts')->assertJsonCount(2, 'standings');
        $this->postJson("{$url}/knockout", ['revision' => $preview['revision']])->assertOk();
        $this->assertSame(4, PadelMatch::where('tier_id', $tier->id)->count());
        $this->assertSame(0, PadelMatch::where('tier_id', $tier->id)->where('stage', 'group')->count());
        $this->getJson('/api/v1/board')->assertJsonCount(2, 'standings')->assertJsonCount(1, 'knockouts')->assertJsonPath('knockouts.0.round_name', 'Perempat final')->assertJsonCount(4, 'knockouts.0.entries')->assertJsonPath('knockouts.0.entries.0.team_a', 'Team 1')->assertJsonPath('knockouts.0.entries.0.status', 'scheduled');
        $court = Court::create(['venue_id' => Venue::firstOrCreate(['name' => 'Venue Utama'])->id, 'name' => 'Court Silver']);
        $first = PadelMatch::where('tier_id', $tier->id)->first();
        $first->update(['court_id' => $court->id, 'status' => 'live']);
        $state = $first->state;
        $state['points'] = [2, 1];
        $first->update(['state' => $state]);
        $this->getJson('/api/v1/board')->assertJsonPath('knockouts.0.entries.0.court_name', 'Court Silver')->assertJsonPath('knockouts.0.entries.0.status', 'live')->assertJsonPath('knockouts.0.entries.0.points', [2, 1]);
        $opening = PadelMatch::where('tier_id', $tier->id)->orderBy('id')->get();
        $this->confirm($opening[0]);
        $this->getJson('/api/v1/board')->assertJsonPath('knockouts.0.round_name', 'Perempat final')->assertJsonPath('knockouts.0.entries.0.winner', 'Team 1');
        foreach ($opening->skip(1) as $match) {
            $this->confirm($match);
        }
        $this->getJson('/api/v1/board')->assertJsonPath('knockouts.0.round_name', 'Semifinal')->assertJsonCount(2, 'knockouts.0.entries');
        $semis = PadelMatch::where('tier_id', $tier->id)->where('status', 'scheduled')->get();
        foreach ($semis as $match) {
            $this->confirm($match);
        }
        $this->getJson('/api/v1/board')->assertJsonPath('knockouts.0.round_name', 'Final')->assertJsonCount(1, 'knockouts.0.entries');
        $final = PadelMatch::where('tier_id', $tier->id)->where('status', 'scheduled')->first();
        $this->confirm($final);
        $this->getJson('/api/v1/board')->assertJsonPath('knockouts.0.champion', 'Team 1')->assertJsonPath('knockouts.0.entries.0.status', 'finished')->assertJsonCount(2, 'standings');
        $this->assertSame(7, PadelMatch::where('tier_id', $tier->id)->count());
        $this->postJson("{$url}/knockout", ['revision' => $preview['revision']])->assertConflict();
        $this->assertNull($groupTier->fresh()->knockout);
    }

    public function test_seeded_sixteen_draw_locks_exact_reference_positions_and_survives_rule_edits(): void
    {
        $this->account();
        $tournament = Tournament::create(['name' => 'Seeded Open', 'venue_id' => Venue::firstOrCreate(['name' => 'Venue Utama'])->id]);
        $teams = array_map(fn (int $i): string => "Team {$i}", range(1, 16));
        $seeds = ['Team 5', 'Team 11', 'Team 3', 'Team 7'];
        $payload = ['name' => 'Seeded', 'tournament_id' => $tournament->id, 'format' => 'knockout', 'draw_mode' => 'seeded', 'seeds' => $seeds, 'rules' => ScoringEngine::defaults(), 'groups' => [['name' => 'Peserta', 'teams' => $teams]]];
        $id = $this->postJson('/api/v1/tiers', $payload)->assertCreated()->assertJsonPath('knockout.seeds', $seeds)->json('id');
        $tier = Tier::findOrFail($id);
        $slots = $tier->knockout['draw_slots'];
        foreach ([0, 15, 8, 7] as $rank => $position) {
            $this->assertSame($seeds[$rank], $slots[$position]['team']);
            $this->assertSame('TOP '.($rank + 1), $slots[$position]['label']);
        }
        $this->assertEqualsCanonicalizing($teams, array_column($slots, 'team'));
        $preview = (new TournamentProgression)->overview($tier);
        $this->getJson("/api/v1/tiers/{$id}/progression")->assertJsonPath('rounds', $preview['rounds']);
        $revision = collect($this->getJson('/api/v1/data')->json('tiers'))->firstWhere('id', $id)['edit_revision'];
        $rules = [...$tier->rules, 'super_win_by_two' => false];
        $this->patchJson("/api/v1/tiers/{$id}", [...$payload, 'rules' => $rules, 'edit_revision' => $revision])->assertOk();
        $this->assertSame($slots, $tier->fresh()->knockout['draw_slots']);
        $this->postJson("/api/v1/tiers/{$id}/knockout", ['revision' => (new TournamentProgression)->overview($tier->fresh())['revision']])->assertOk();
        $this->getJson("/api/v1/tiers/{$id}/progression")->assertJsonPath('rounds.0.matches.0.a.team', 'Team 5')->assertJsonPath('rounds.0.matches.7.b.team', 'Team 11');
        $revision = collect($this->getJson('/api/v1/data')->json('tiers'))->firstWhere('id', $id)['edit_revision'];
        $this->patchJson("/api/v1/tiers/{$id}", [...$payload, 'seeds' => array_reverse($seeds), 'edit_revision' => $revision])->assertConflict();
    }

    public function test_changing_seeds_rebuilds_unconfirmed_draw_and_releases_old_court_reservations(): void
    {
        $this->account();
        $tournament = Tournament::create(['name' => 'Seeded Open', 'venue_id' => Venue::firstOrCreate(['name' => 'Venue Utama'])->id]);
        $teams = array_map(fn (int $i): string => "Team {$i}", range(1, 8));
        $payload = ['name' => 'Seeded', 'tournament_id' => $tournament->id, 'format' => 'knockout', 'draw_mode' => 'seeded', 'seeds' => array_slice($teams, 0, 4), 'rules' => ScoringEngine::defaults(), 'groups' => [['name' => 'Peserta', 'teams' => $teams]]];
        $id = $this->postJson('/api/v1/tiers', $payload)->assertCreated()->json('id');
        $tier = Tier::findOrFail($id);
        $court = Court::create(['name' => 'Court 1', 'venue_id' => $tournament->venue_id]);
        $reservation = ['round' => 0, 'position' => 0, 'court_id' => $court->id, 'revision' => (new TournamentProgression)->scheduleRevision($tier)];
        $this->postJson("/api/v1/tiers/{$id}/knockout-schedule", $reservation)->assertOk();
        $revision = collect($this->getJson('/api/v1/data')->json('tiers'))->firstWhere('id', $id)['edit_revision'];
        $seeds = ['Team 8', 'Team 7', 'Team 6', 'Team 5'];
        $this->patchJson("/api/v1/tiers/{$id}", [...$payload, 'seeds' => $seeds, 'edit_revision' => $revision])->assertOk()->assertJsonPath('knockout.seeds', $seeds);
        $this->assertSame('Team 8', $tier->fresh()->knockout['draw_slots'][0]['team']);
        $this->assertSame('Team 7', $tier->fresh()->knockout['draw_slots'][7]['team']);
        $this->assertDatabaseCount('court_reservations', 0);
        $this->postJson("/api/v1/tiers/{$id}/knockout-schedule", $reservation)->assertConflict();
    }

    public function test_seeded_draws_support_two_or_four_seeds_and_prioritize_byes_up_to_sixty_four(): void
    {
        $this->account();
        $tournament = Tournament::create(['name' => 'Seeded Open', 'venue_id' => Venue::firstOrCreate(['name' => 'Venue Utama'])->id]);
        foreach ([2, 3, 4, 5, 6, 7, 8, 9, 15, 16, 17, 31, 32, 33, 48, 63, 64] as $count) {
            $teams = array_map(fn (int $i): string => "Team {$i}", range(1, $count));
            foreach ($count >= 4 ? [2, 4] : [2] as $seedCount) {
                $seeds = array_slice($teams, 0, $seedCount);
                $id = $this->postJson('/api/v1/tiers', ['name' => 'Seeded', 'tournament_id' => $tournament->id, 'format' => 'knockout', 'draw_mode' => 'seeded', 'seeds' => $seeds, 'rules' => ScoringEngine::defaults(), 'groups' => [['name' => 'Peserta', 'teams' => $teams]]])->assertCreated()->json('id');
                $tier = Tier::findOrFail($id);
                $slots = $tier->knockout['draw_slots'];
                $size = count($slots);
                $this->assertEqualsCanonicalizing($teams, array_values(array_filter(array_column($slots, 'team'))));
                $this->assertSame($size - $count, count(array_filter($slots, fn (array $slot): bool => $slot['team'] === null)));
                foreach (array_slice([0, $size - 1, intdiv($size, 2), intdiv($size, 2) - 1], 0, $seedCount) as $rank => $position) {
                    $this->assertSame($seeds[$rank], $slots[$position]['team']);
                    if ($rank < $size - $count) {
                        $this->assertNull($slots[$position ^ 1]['team']);
                    }
                }
                $overview = (new TournamentProgression)->overview($tier);
                foreach ($overview['rounds'][0]['matches'] as $node) {
                    $this->assertTrue($node['a']['team'] !== null || $node['b']['team'] !== null);
                }
                if ($count === 5 || $count === 64) {
                    $this->postJson("/api/v1/tiers/{$id}/knockout", ['revision' => $overview['revision']])->assertOk();
                    while ($match = PadelMatch::where('tier_id', $id)->where('status', 'scheduled')->first()) {
                        $this->confirm($match);
                    }
                    $this->assertSame($count - 1, PadelMatch::where('tier_id', $id)->count());
                    $rounds = $tier->fresh()->knockout['rounds'];
                    $this->assertNotNull($rounds[count($rounds) - 1]['matches'][0]['winner']);
                }
            }
        }
    }

    public function test_seeded_draw_rejects_missing_duplicate_unknown_or_invalid_number_of_seeds(): void
    {
        $this->account();
        $tournament = Tournament::create(['name' => 'Seeded Open', 'venue_id' => Venue::firstOrCreate(['name' => 'Venue Utama'])->id]);
        $payload = ['name' => 'Seeded', 'tournament_id' => $tournament->id, 'format' => 'knockout', 'draw_mode' => 'seeded', 'rules' => ScoringEngine::defaults(), 'groups' => [['name' => 'Peserta', 'teams' => ['A', 'B', 'C', 'D']]]];
        $this->postJson('/api/v1/tiers', $payload)->assertUnprocessable()->assertJsonValidationErrors('seeds');
        foreach ([['A', 'A'], ['A', 'unknown'], ['A', 'B', 'C'], ['A'], ['A', 'B', 'C', 'D', 'E'], ['top1' => 'A', 'top2' => 'B']] as $seeds) {
            $this->postJson('/api/v1/tiers', [...$payload, 'seeds' => $seeds])->assertUnprocessable();
        }
        $this->postJson('/api/v1/tiers', [...$payload, 'format' => 'group', 'seeds' => ['A', 'B']])->assertUnprocessable();
        $this->assertDatabaseCount('tiers', 0);
    }

    public function test_sixty_four_team_random_draw_preserves_every_team_and_does_not_redraw_on_refresh(): void
    {
        $this->account();
        $tier = $this->directKnockout(64, 'random');
        $teams = array_map(fn (int $index): string => "Team {$index}", range(1, 64));
        $this->assertEqualsCanonicalizing($teams, $tier->groups[0]['teams']);
        $this->assertSame('random', $tier->knockout['draw_mode']);
        $overview = (new TournamentProgression)->overview($tier);
        $this->assertSame('Babak 64 besar', $overview['rounds'][0]['name']);
        $this->assertCount(6, $overview['rounds']);
        $this->getJson("/api/v1/tiers/{$tier->id}/progression")->assertJsonPath('rounds', $overview['rounds'])->assertJsonPath('revision', $overview['revision']);
        $this->postJson("/api/v1/tiers/{$tier->id}/knockout", ['revision' => $overview['revision']])->assertOk();
        $opening = PadelMatch::where('tier_id', $tier->id)->get();
        $this->assertCount(32, $opening);
        $this->assertEqualsCanonicalizing($teams, $opening->flatMap(fn (PadelMatch $match): array => [$match->team_a, $match->team_b])->all());
    }

    public function test_direct_knockout_validates_size_and_rejects_referee_confirmation(): void
    {
        $this->account();
        $tournament = Tournament::create(['venue_id' => Venue::firstOrCreate(['name' => 'Venue Utama'])->id, 'name' => 'Invalid Draw']);
        $payload = ['tournament_id' => $tournament->id, 'name' => 'Invalid', 'format' => 'knockout', 'rules' => ScoringEngine::defaults(), 'groups' => [['name' => 'List', 'teams' => array_map(fn (int $index): string => "Team {$index}", range(1, 65))]]];
        $this->postJson('/api/v1/tiers', $payload)->assertUnprocessable()->assertJsonValidationErrors('groups.0.teams');
        $this->postJson('/api/v1/tiers', [...$payload, 'format' => 'group', 'groups' => [['name' => 'A', 'teams' => array_map(fn (int $index): string => "Team {$index}", range(1, 17))]]])->assertUnprocessable()->assertJsonValidationErrors('groups.0.teams');
        $payload['groups'] = [['name' => 'A', 'teams' => ['A', 'B']], ['name' => 'B', 'teams' => ['C', 'D']]];
        $this->postJson('/api/v1/tiers', $payload)->assertUnprocessable();
        $tier = $this->directKnockout();
        $overview = (new TournamentProgression)->overview($tier);
        $this->postJson("/api/v1/tiers/{$tier->id}/progression", ['qualifiers' => 1])->assertUnprocessable();
        $this->account('referee');
        $this->postJson("/api/v1/tiers/{$tier->id}/knockout", ['revision' => $overview['revision']])->assertForbidden();
        $this->assertSame(0, PadelMatch::count());
    }

    public function test_all_direct_knockout_sizes_distribute_byes_and_finish_with_exactly_one_champion(): void
    {
        $this->account();
        foreach ([...range(2, 16), 17, 31, 32, 33, 48, 63, 64] as $count) {
            $tier = $this->directKnockout($count);
            $overview = (new TournamentProgression)->overview($tier);
            $size = 2;
            while ($size < $count) {
                $size *= 2;
            }
            $opening = $overview['rounds'][0]['matches'];
            $this->assertCount(intdiv($size, 2), $opening);
            $byes = array_values(array_filter($opening, fn (array $node): bool => $node['bye']));
            $this->assertCount($size - $count, $byes);
            $entrants = [];
            foreach ($opening as $node) {
                $this->assertNotNull($node['a']['team']);
                $this->assertSame($node['bye'], $node['b']['team'] === null);
                $entrants[] = $node['a']['team'];
                if ($node['b']['team'] !== null) {
                    $entrants[] = $node['b']['team'];
                }
            }
            $this->assertEqualsCanonicalizing($tier->groups[0]['teams'], $entrants);
            $this->assertEqualsCanonicalizing(array_slice($tier->groups[0]['teams'], 0, $size - $count), array_map(fn (array $node): string => $node['a']['team'], $byes));
            if (count($opening) > 1) {
                $half = intdiv(count($opening), 2);
                $left = count(array_filter(array_slice($opening, 0, $half), fn (array $node): bool => $node['bye']));
                $right = count(array_filter(array_slice($opening, $half), fn (array $node): bool => $node['bye']));
                $this->assertLessThanOrEqual(1, abs($left - $right));
            }
            $this->postJson("/api/v1/tiers/{$tier->id}/knockout", ['revision' => $overview['revision']])->assertOk();
            $confirmed = $tier->fresh()->knockout['rounds'][0]['matches'];
            foreach ($confirmed as $node) {
                if ($node['bye']) {
                    $this->assertNull($node['match_id']);
                    $this->assertSame($node['a']['team'], $node['winner']);
                }
            }
            $played = 0;
            while ($match = PadelMatch::where('tier_id', $tier->id)->where('status', 'scheduled')->first()) {
                $this->assertLessThan($count, ++$played);
                $this->assertNotEmpty($match->team_a);
                $this->assertNotEmpty($match->team_b);
                $this->assertNotSame($match->team_a, $match->team_b);
                $this->confirm($match);
            }
            $this->assertSame($count - 1, PadelMatch::where('tier_id', $tier->id)->count());
            $this->assertNotNull($tier->fresh()->knockout['rounds'][count($overview['rounds']) - 1]['matches'][0]['winner']);
        }
    }

    public function test_random_draw_with_byes_is_stable_and_tv_shows_automatic_advancement(): void
    {
        $this->account();
        $tier = $this->directKnockout(6, 'random');
        $overview = (new TournamentProgression)->overview($tier);
        $this->getJson("/api/v1/tiers/{$tier->id}/progression")->assertJsonPath('rounds', $overview['rounds'])->assertJsonPath('revision', $overview['revision']);
        $this->postJson("/api/v1/tiers/{$tier->id}/knockout", ['revision' => $overview['revision']])->assertOk();
        $broadcast = $this->getJson('/api/v1/board')->assertOk()->assertJsonPath('knockouts.0.round_name', 'Perempat final')->json('knockouts.0.entries');
        $byes = array_values(array_filter($broadcast, fn (array $entry): bool => $entry['bye']));
        $this->assertCount(2, $byes);
        $this->assertEqualsCanonicalizing(array_slice($tier->groups[0]['teams'], 0, 2), array_column($byes, 'winner'));
        foreach ($byes as $bye) {
            $this->assertNull($bye['team_b']);
            $this->assertSame('BYE', $bye['label_b']);
        }
        foreach (PadelMatch::where('tier_id', $tier->id)->where('status', 'scheduled')->get() as $match) {
            $this->confirm($match);
        }
        $this->getJson('/api/v1/board')->assertJsonPath('knockouts.0.round_name', 'Semifinal');
    }

    public function test_stale_preview_cannot_confirm_a_changed_draw(): void
    {
        $this->account();
        $tier = $this->tier();
        $revision = (new TournamentProgression)->overview($tier)['revision'];
        $this->postJson("/api/v1/tiers/{$tier->id}/progression", ['qualifiers' => 1])->assertOk();
        $this->postJson("/api/v1/tiers/{$tier->id}/knockout", ['revision' => $revision])->assertConflict();
        $this->assertSame(0, PadelMatch::where('stage', 'knockout')->count());
    }

    public function test_two_way_ties_use_head_to_head_before_manual_order(): void
    {
        $this->account();
        $tier = $this->tier(1);
        PadelMatch::query()->delete();
        $tier->update(['groups' => [['name' => 'Grup A', 'teams' => ['B', 'A', 'C', 'D']]]]);
        foreach ([['A', 'B'], ['B', 'C'], ['C', 'A'], ['A', 'D'], ['B', 'D'], ['D', 'C']] as [$a, $b]) {
            $this->game($tier, 'Grup A', $a, $b);
        }
        $this->postJson("/api/v1/tiers/{$tier->id}/progression", ['qualifiers' => 2, 'tie_orders' => ['Grup A' => ['B', 'A', 'C', 'D']]])->assertOk();
        $this->getJson("/api/v1/tiers/{$tier->id}/progression")->assertJsonPath('ready', true)->assertJsonPath('groups.0.rows.0.team', 'A')->assertJsonPath('groups.0.rows.2.team', 'D');
    }
}
