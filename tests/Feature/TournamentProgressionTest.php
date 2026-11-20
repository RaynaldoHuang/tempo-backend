<?php

namespace Tests\Feature;

use App\Models\Court;
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

    public function test_sixteen_team_random_draw_preserves_every_team_and_does_not_redraw_on_refresh(): void
    {
        $this->account();
        $tier = $this->directKnockout(16, 'random');
        $teams = array_map(fn (int $index): string => "Team {$index}", range(1, 16));
        $this->assertEqualsCanonicalizing($teams, $tier->groups[0]['teams']);
        $this->assertSame('random', $tier->knockout['draw_mode']);
        $overview = (new TournamentProgression)->overview($tier);
        $this->assertSame('Babak 16 besar', $overview['rounds'][0]['name']);
        $this->assertCount(4, $overview['rounds']);
        $this->getJson("/api/v1/tiers/{$tier->id}/progression")->assertJsonPath('rounds', $overview['rounds'])->assertJsonPath('revision', $overview['revision']);
        $this->postJson("/api/v1/tiers/{$tier->id}/knockout", ['revision' => $overview['revision']])->assertOk();
        $opening = PadelMatch::where('tier_id', $tier->id)->get();
        $this->assertCount(8, $opening);
        $this->assertEqualsCanonicalizing($teams, $opening->flatMap(fn (PadelMatch $match): array => [$match->team_a, $match->team_b])->all());
    }

    public function test_direct_knockout_validates_size_and_rejects_referee_confirmation(): void
    {
        $this->account();
        $tournament = Tournament::create(['venue_id' => Venue::firstOrCreate(['name' => 'Venue Utama'])->id, 'name' => 'Invalid Draw']);
        $payload = ['tournament_id' => $tournament->id, 'name' => 'Invalid', 'format' => 'knockout', 'rules' => ScoringEngine::defaults(), 'groups' => [['name' => 'List', 'teams' => array_map(fn (int $index): string => "Team {$index}", range(1, 17))]]];
        $this->postJson('/api/v1/tiers', $payload)->assertUnprocessable();
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
        foreach (range(2, 16) as $count) {
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
