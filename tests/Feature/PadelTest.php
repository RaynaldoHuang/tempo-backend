<?php

namespace Tests\Feature;

use App\Models\Court;
use App\Models\PadelMatch;
use App\Models\ScoringPreset;
use App\Models\Tier;
use App\Models\Tournament;
use App\Models\User;
use App\Models\Venue;
use App\Services\ScoringEngine;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PadelTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'admin'): User
    {
        $token = Str::random(40);
        $user = User::factory()->create(['role' => $role, 'api_token' => hash('sha256', $token), 'token_expires_at' => now()->addDay()]);
        $this->withToken($token);

        return $user;
    }

    private function fixture(): PadelMatch
    {
        $tournament = Tournament::create(['venue_id' => Venue::firstOrCreate(['name' => 'Venue Utama'])->id, 'name' => 'Tempo Open']);
        $tier = Tier::create(['tournament_id' => $tournament->id, 'name' => 'Bronze', 'format' => 'group', 'groups' => [], 'rules' => ScoringEngine::defaults()]);
        $court = Court::create(['venue_id' => Venue::firstOrCreate(['name' => 'Venue Utama'])->id, 'name' => 'Court 1']);

        return PadelMatch::create(['tier_id' => $tier->id, 'court_id' => $court->id, 'queue_position' => 1, 'group_name' => 'A', 'team_a' => 'A1 / A2', 'team_b' => 'B1 / B2', 'state' => (new ScoringEngine)->initial(), 'rules' => ScoringEngine::defaults()]);
    }

    private function points(array $state, int $team, int $count, ?array $rules = null): array
    {
        $engine = new ScoringEngine;
        for ($i = 0; $i < $count; $i++) {
            $state = $engine->point($state, $team, $rules ?? ScoringEngine::defaults());
        }

        return $state;
    }

    public function test_split_sets_enter_super_tiebreak_and_require_two_point_lead(): void
    {
        $state = $this->points((new ScoringEngine)->initial(), 0, 24);
        $this->assertNull($state['winner']);
        $state = $this->points($state, 1, 24);
        $this->assertSame('super', $state['mode']);
        $state = $this->points($state, 0, 9);
        $state = $this->points($state, 1, 9);
        $state = $this->points($state, 0, 1);
        $this->assertNull($state['winner']);
        $state = $this->points($state, 0, 1);
        $this->assertSame(0, $state['winner']);
        $this->assertSame([11, 9], $state['sets'][2]['games']);
    }

    public function test_straight_sets_finish_without_super_tiebreak(): void
    {
        $state = $this->points((new ScoringEngine)->initial(), 1, 48);
        $this->assertSame(1, $state['winner']);
        $this->assertCount(2, $state['sets']);
    }

    public function test_deuce_advantage_and_golden_point(): void
    {
        $state = $this->points((new ScoringEngine)->initial(), 0, 3);
        $state = $this->points($state, 1, 3);
        $advantage = $this->points($state, 0, 1);
        $this->assertSame([0, 0], $advantage['games']);
        $deuce = $this->points($advantage, 1, 1);
        $won = $this->points($deuce, 1, 2);
        $this->assertSame([0, 1], $won['games']);
        $golden = $this->points($state, 0, 1, [...ScoringEngine::defaults(), 'golden_point' => true]);
        $this->assertSame([1, 0], $golden['games']);
    }

    public function test_one_advantage_then_golden_point_for_either_team_and_each_new_game(): void
    {
        $rules = [...ScoringEngine::defaults(), 'deuce_rule' => 'one_advantage'];
        foreach ([0, 1] as $leader) {
            foreach ([0, 1] as $winner) {
                $state = $this->points((new ScoringEngine)->initial(), 0, 3, $rules);
                $state = $this->points($state, 1, 3, $rules);
                $state = $this->points($state, $leader, 1, $rules);
                $this->assertSame([0, 0], $state['games']);
                $this->assertSame(4, $state['points'][$leader]);
                $consecutive = $this->points($state, $leader, 1, $rules);
                $this->assertSame(1, $consecutive['games'][$leader]);
                $state = $this->points($state, 1 - $leader, 1, $rules);
                $this->assertSame([4, 4], $state['points']);
                $this->assertSame([0, 0], $state['games']);
                $state = $this->points($state, $winner, 1, $rules);
                $this->assertSame(1, $state['games'][$winner]);
                $this->assertSame([0, 0], $state['points']);
                $state = $this->points($state, 0, 3, $rules);
                $state = $this->points($state, 1, 3, $rules);
                $state = $this->points($state, 0, 1, $rules);
                $this->assertSame(1, array_sum($state['games']));
                $this->assertSame([4, 3], $state['points']);
            }
        }
    }

    public function test_one_advantage_rule_does_not_change_tiebreak_or_super_tiebreak_margin(): void
    {
        $rules = [...ScoringEngine::defaults(), 'deuce_rule' => 'one_advantage'];
        foreach (['tiebreak' => 6, 'super' => 9] as $mode => $tied) {
            $state = (new ScoringEngine)->initial();
            $state['mode'] = $mode;
            $state['points'] = [$tied, $tied];
            $state = $this->points($state, 0, 1, $rules);
            $this->assertNull($state['winner']);
            $this->assertCount(0, $state['sets']);
            $state = $this->points($state, 0, 1, $rules);
            $this->assertCount(1, $state['sets']);
        }
    }

    public function test_new_deuce_rule_is_saved_to_presets_and_matches_and_rejects_unknown_rule(): void
    {
        $this->account();
        $rules = [...ScoringEngine::defaults(), 'deuce_rule' => 'one_advantage'];
        $this->postJson('/api/v1/presets', ['name' => 'One advantage', 'rules' => $rules])->assertCreated()->assertJsonPath('rules.deuce_rule', 'one_advantage');
        $tournament = Tournament::create(['name' => 'Open', 'venue_id' => Venue::firstOrCreate(['name' => 'Venue Utama'])->id]);
        $this->postJson('/api/v1/tiers', ['name' => 'Bronze', 'tournament_id' => $tournament->id, 'format' => 'direct', 'rules' => $rules, 'groups' => [['name' => 'Final', 'teams' => ['One', 'Two']]]])->assertCreated()->assertJsonPath('rules.deuce_rule', 'one_advantage');
        $this->assertSame('one_advantage', PadelMatch::first()->rules['deuce_rule']);
        $this->postJson('/api/v1/presets', ['name' => 'Invalid', 'rules' => [...$rules, 'deuce_rule' => 'unknown']])->assertUnprocessable()->assertJsonValidationErrors('rules.deuce_rule');
    }

    public function test_undo_golden_point_restores_second_deuce_and_either_team_can_win_next_point(): void
    {
        $this->account();
        $match = $this->fixture();
        $state = (new ScoringEngine)->initial();
        $state['points'] = [4, 4];
        $state['games'] = [1, 0];
        $match->update(['status' => 'live', 'state' => $state, 'rules' => [...ScoringEngine::defaults(), 'regular_sets' => 1, 'games_to_win' => 2, 'deuce_rule' => 'one_advantage']]);
        $url = "/api/v1/matches/{$match->id}/score";
        $this->postJson($url, ['action' => 'point', 'team' => 0, 'version' => 0, 'command_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('status', 'review')->assertJsonPath('state.winner', 0);
        $this->postJson($url, ['action' => 'undo', 'version' => 1, 'command_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('status', 'live')->assertJsonPath('state.points', [4, 4])->assertJsonPath('state.games', [1, 0]);
        $this->postJson($url, ['action' => 'point', 'team' => 1, 'version' => 2, 'command_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('state.games', [1, 1])->assertJsonPath('state.points', [0, 0]);
    }

    public function test_tiebreak_at_six_all_and_service_rotation(): void
    {
        $state = (new ScoringEngine)->initial();
        for ($i = 0; $i < 6; $i++) {
            $state = $this->points($state, 0, 4);
            $state = $this->points($state, 1, 4);
        }
        $this->assertSame('tiebreak', $state['mode']);
        $start = $state['server'];
        $state = $this->points($state, 0, 1);
        $this->assertSame(($start + 1) % 4, $state['server']);
        $state = $this->points($state, 1, 1);
        $this->assertSame(($start + 1) % 4, $state['server']);
        $state = $this->points($state, 0, 1);
        $this->assertSame(($start + 2) % 4, $state['server']);
        $state = $this->points($state, 0, 5);
        $this->assertSame([7, 6], $state['sets'][0]['games']);
        $this->assertSame(($start + 1) % 4, $state['server']);
    }

    public function test_first_to_seven_tiebreak_at_five_all_finishes_at_seven_six_for_either_team(): void
    {
        $rules = [...ScoringEngine::defaults(), 'regular_sets' => 1, 'tiebreak_at' => 5, 'tiebreak_win_by_two' => false];
        foreach ([0, 1] as $winner) {
            $state = (new ScoringEngine)->initial();
            for ($i = 0; $i < 5; $i++) {
                $state = $this->points($state, 0, 4, $rules);
                $state = $this->points($state, 1, 4, $rules);
                $this->assertSame($i === 4 ? 'tiebreak' : 'regular', $state['mode']);
            }
            $this->assertSame([5, 5], $state['games']);
            $state = $this->points($state, 0, 6, $rules);
            $state = $this->points($state, 1, 6, $rules);
            $this->assertNull($state['winner']);
            $state = $this->points($state, $winner, 1, $rules);
            $this->assertSame($winner, $state['winner']);
            $this->assertSame(6, $state['sets'][0]['games'][$winner]);
            $this->assertSame(5, $state['sets'][0]['games'][1 - $winner]);
            $this->assertSame(7, $state['sets'][0]['tiebreak'][$winner]);
            $this->assertSame(6, $state['sets'][0]['tiebreak'][1 - $winner]);
            $this->assertSame($state, $this->points($state, 1 - $winner, 1, $rules));
        }
    }

    public function test_legacy_rules_still_require_two_point_tiebreak_lead(): void
    {
        $rules = ScoringEngine::defaults();
        unset($rules['tiebreak_win_by_two']);
        $state = (new ScoringEngine)->initial();
        for ($i = 0; $i < 6; $i++) {
            $state = $this->points($state, 0, 4, $rules);
            $state = $this->points($state, 1, 4, $rules);
        }
        $state = $this->points($state, 0, 6, $rules);
        $state = $this->points($state, 1, 6, $rules);
        $state = $this->points($state, 0, 1, $rules);
        $this->assertCount(0, $state['sets']);
        $state = $this->points($state, 0, 1, $rules);
        $this->assertSame([8, 6], $state['sets'][0]['tiebreak']);
    }

    public function test_first_to_target_option_does_not_remove_super_tiebreak_margin(): void
    {
        $rules = [...ScoringEngine::defaults(), 'tiebreak_win_by_two' => false];
        $state = $this->points((new ScoringEngine)->initial(), 0, 24, $rules);
        $state = $this->points($state, 1, 24, $rules);
        $state = $this->points($state, 0, 9, $rules);
        $state = $this->points($state, 1, 9, $rules);
        $state = $this->points($state, 0, 1, $rules);
        $this->assertNull($state['winner']);
        $state = $this->points($state, 0, 1, $rules);
        $this->assertSame(0, $state['winner']);
    }

    public function test_custom_tiebreak_rules_persist_to_presets_tiers_and_matches(): void
    {
        $this->account();
        $rules = [...ScoringEngine::defaults(), 'tiebreak_at' => 5, 'tiebreak_win_by_two' => false];
        $this->postJson('/api/v1/presets', ['name' => 'First to seven', 'rules' => $rules])->assertCreated()->assertJsonPath('rules.tiebreak_at', 5)->assertJsonPath('rules.tiebreak_win_by_two', false);
        $tournament = Tournament::create(['venue_id' => Venue::firstOrCreate(['name' => 'Venue Utama'])->id, 'name' => 'Short Set']);
        $this->postJson('/api/v1/tiers', ['tournament_id' => $tournament->id, 'name' => 'Short', 'format' => 'direct', 'rules' => $rules, 'groups' => [['name' => 'Final', 'teams' => ['One', 'Two']]]])->assertCreated();
        foreach ($rules as $key => $value) {
            $this->assertSame($value, Tier::first()->rules[$key]);
            $this->assertSame($value, PadelMatch::first()->rules[$key]);
        }
        $this->postJson('/api/v1/presets', ['name' => 'Invalid trigger', 'rules' => [...$rules, 'tiebreak_at' => 7]])->assertUnprocessable()->assertJsonValidationErrors('rules.tiebreak_at');
        $this->postJson('/api/v1/presets', ['name' => 'Invalid margin', 'rules' => [...$rules, 'tiebreak_win_by_two' => 'invalid']])->assertUnprocessable()->assertJsonValidationErrors('rules.tiebreak_win_by_two');
    }

    public function test_scoring_is_versioned_idempotent_and_undoable(): void
    {
        $this->account();
        $match = $this->fixture();
        $url = "/api/v1/matches/{$match->id}/score";
        $this->postJson($url, ['action' => 'start', 'server' => 0, 'version' => 0, 'command_id' => Str::uuid()->toString()])->assertOk();
        $point = ['action' => 'point', 'team' => 0, 'version' => 1, 'command_id' => Str::uuid()->toString()];
        $this->postJson($url, $point)->assertOk()->assertJsonPath('state.points.0', 1);
        $this->postJson($url, $point)->assertOk()->assertJsonPath('state.points.0', 1);
        $this->postJson($url, [...$point, 'command_id' => Str::uuid()->toString()])->assertConflict();
        $this->postJson($url, ['action' => 'undo', 'version' => 2, 'command_id' => Str::uuid()->toString()])->assertOk()->assertJsonPath('state.points.0', 0);
        $this->assertDatabaseCount('score_events', 3);
    }

    public function test_referee_cannot_score_another_court_or_create_accounts(): void
    {
        $this->account('referee');
        $match = $this->fixture();
        $this->postJson("/api/v1/matches/{$match->id}/score", ['action' => 'start', 'server' => 0, 'version' => 0, 'command_id' => Str::uuid()->toString()])->assertForbidden();
        $this->postJson('/api/v1/referees', [])->assertForbidden();
    }

    public function test_tier_generates_round_robin_and_queue_prevents_overlapping_matches(): void
    {
        $this->account();
        $this->seed(DatabaseSeeder::class);
        $tournament = Tournament::create(['venue_id' => Venue::firstOrCreate(['name' => 'Venue Utama'])->id, 'name' => 'Tempo']);
        $this->postJson('/api/v1/tiers', ['tournament_id' => $tournament->id, 'name' => 'Bronze', 'format' => 'group', 'rules' => ScoringEngine::defaults(), 'groups' => [['name' => 'A', 'teams' => ['One', 'Two', 'Three']]]])->assertCreated();
        $this->assertDatabaseCount('padel_matches', 3);
        $matches = PadelMatch::all();
        $court = Court::first();
        $this->postJson("/api/v1/matches/{$matches[0]->id}/assign", ['court_id' => $court->id])->assertOk();
        $this->postJson("/api/v1/matches/{$matches[1]->id}/assign", ['court_id' => $court->id])->assertOk()->assertJsonPath('queue_position', 2);
        $this->postJson("/api/v1/matches/{$matches[1]->id}/score", ['action' => 'start', 'server' => 0, 'version' => 1, 'command_id' => Str::uuid()->toString()])->assertConflict();
        $this->postJson("/api/v1/matches/{$matches[0]->id}/score", ['action' => 'start', 'server' => 0, 'version' => 1, 'command_id' => Str::uuid()->toString()])->assertOk();
        $this->postJson("/api/v1/matches/{$matches[1]->id}/assign", ['court_id' => Court::skip(1)->first()->id])->assertOk();
        $this->postJson("/api/v1/matches/{$matches[1]->id}/score", ['action' => 'start', 'server' => 0, 'version' => 2, 'command_id' => Str::uuid()->toString()])->assertConflict();
    }

    public function test_login_expiration_and_public_board_do_not_expose_credentials(): void
    {
        $user = User::factory()->create(['password' => 'password-for-test', 'role' => 'referee']);
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        $response = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password-for-test'])->assertOk();
        $this->assertArrayNotHasKey('api_token', $response->json('user'));
        $this->withToken($response->json('token'))->getJson('/api/v1/data')->assertOk();
        $this->postJson('/api/v1/logout')->assertOk();
        $this->getJson('/api/v1/data')->assertUnauthorized();
        $this->seed(DatabaseSeeder::class);
        $board = $this->getJson('/api/v1/board')->assertOk()->assertJsonCount(7, 'courts');
        $this->assertStringNotContainsString($user->email, $board->getContent());
    }

    public function test_undo_winning_point_reopens_match_before_confirmation(): void
    {
        $this->account();
        $match = $this->fixture();
        $match->rules = [...ScoringEngine::defaults(), 'regular_sets' => 1, 'games_to_win' => 2];
        $match->state = $this->points((new ScoringEngine)->initial(), 0, 7, $match->rules);
        $match->status = 'live';
        $match->save();
        $url = "/api/v1/matches/{$match->id}/score";
        $this->postJson($url, ['action' => 'point', 'team' => 0, 'version' => 0, 'command_id' => Str::uuid()->toString()])->assertOk()->assertJsonPath('status', 'review');
        $this->postJson($url, ['action' => 'undo', 'version' => 1, 'command_id' => Str::uuid()->toString()])->assertOk()->assertJsonPath('status', 'live')->assertJsonPath('state.winner', null);
    }

    public function test_seven_courts_can_run_together_with_isolated_referee_access(): void
    {
        $this->account();
        $this->seed(DatabaseSeeder::class);
        $tournament = Tournament::create(['venue_id' => Venue::firstOrCreate(['name' => 'Venue Utama'])->id, 'name' => 'Seven Courts']);
        $groups = [];
        for ($i = 1; $i <= 7; $i++) {
            $groups[] = ['name' => "Grup {$i}", 'teams' => ["A{$i}", "B{$i}"]];
        }
        $this->postJson('/api/v1/tiers', ['tournament_id' => $tournament->id, 'name' => 'Bronze', 'format' => 'group', 'rules' => ScoringEngine::defaults(), 'groups' => $groups])->assertCreated();
        foreach (PadelMatch::all() as $index => $match) {
            $this->postJson("/api/v1/matches/{$match->id}/assign", ['court_id' => Court::skip($index)->first()->id])->assertOk();
            $this->postJson("/api/v1/matches/{$match->id}/score", ['action' => 'start', 'server' => 0, 'version' => 1, 'command_id' => Str::uuid()->toString()])->assertOk();
        }
        $referee = $this->account('referee');
        $court = Court::first();
        $court->referee_id = $referee->id;
        $court->save();
        $match = PadelMatch::where('court_id', $court->id)->first();
        $this->getJson('/api/v1/data')->assertOk()->assertJsonCount(1, 'matches')->assertJsonCount(0, 'referees');
        $this->postJson("/api/v1/matches/{$match->id}/score", ['action' => 'point', 'team' => 1, 'version' => 2, 'command_id' => Str::uuid()->toString()])->assertOk();
        $board = $this->getJson('/api/v1/board')->assertOk()->assertJsonCount(7, 'courts');
        $this->assertCount(7, array_filter($board->json('courts'), fn (array $court): bool => $court['match']['status'] === 'live'));
        $board->assertJsonPath('courts.0.match.state.points.1', 1);
    }

    public function test_pause_confirm_and_admin_reopen_workflow(): void
    {
        $this->account();
        $match = $this->fixture();
        $match->status = 'live';
        $match->save();
        $url = "/api/v1/matches/{$match->id}/score";
        $this->postJson($url, ['action' => 'pause', 'version' => 0, 'command_id' => Str::uuid()->toString()])->assertOk()->assertJsonPath('status', 'paused');
        $this->postJson($url, ['action' => 'point', 'team' => 0, 'version' => 1, 'command_id' => Str::uuid()->toString()])->assertConflict();
        $this->postJson($url, ['action' => 'resume', 'version' => 1, 'command_id' => Str::uuid()->toString()])->assertOk()->assertJsonPath('status', 'live');
        $match->refresh();
        $match->state = $this->points((new ScoringEngine)->initial(), 0, 48);
        $match->status = 'review';
        $match->save();
        $this->postJson($url, ['action' => 'confirm', 'version' => 2, 'command_id' => Str::uuid()->toString()])->assertOk()->assertJsonPath('status', 'finished');
        $this->postJson($url, ['action' => 'undo', 'version' => 3, 'command_id' => Str::uuid()->toString()])->assertConflict();
        $this->postJson($url, ['action' => 'reopen', 'version' => 3, 'command_id' => Str::uuid()->toString()])->assertOk()->assertJsonPath('status', 'review');
    }

    public function test_presets_are_persisted_and_invalid_rules_are_rejected(): void
    {
        $this->account();
        $rules = [...ScoringEngine::defaults(), 'golden_point' => true, 'games_to_win' => 4];
        $this->postJson('/api/v1/presets', ['name' => 'Silver Short', 'rules' => $rules])->assertCreated();
        $this->assertDatabaseHas('scoring_presets', ['name' => 'Silver Short']);
        $this->getJson('/api/v1/data')->assertJsonPath('presets.0.rules.games_to_win', 4);
        $this->postJson('/api/v1/presets', ['name' => 'Invalid', 'rules' => [...$rules, 'super_points' => 0]])->assertUnprocessable();
        $this->assertDatabaseCount('scoring_presets', 1);
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('courts', 7);
        $this->assertDatabaseCount('scoring_presets', 2);
    }

    public function test_admin_can_delete_preset_without_changing_existing_rules(): void
    {
        $this->account();
        $match = $this->fixture();
        $preset = ScoringPreset::create(['name' => 'Disposable', 'rules' => $match->rules]);
        $kept = ScoringPreset::create(['name' => 'Keep', 'rules' => ScoringEngine::defaults()]);
        $rules = $match->rules;
        $this->deleteJson("/api/v1/presets/{$preset->id}")->assertOk();
        $this->assertDatabaseMissing('scoring_presets', ['id' => $preset->id]);
        $this->assertDatabaseHas('scoring_presets', ['id' => $kept->id]);
        $this->assertSame($rules, $match->fresh()->rules);
        $this->assertSame($rules, Tier::findOrFail($match->tier_id)->rules);
        $this->getJson('/api/v1/data')->assertJsonCount(1, 'presets')->assertJsonPath('presets.0.id', $kept->id);
        $this->deleteJson("/api/v1/presets/{$preset->id}")->assertNotFound();
    }

    public function test_preset_deletion_requires_admin(): void
    {
        $preset = ScoringPreset::create(['name' => 'Protected', 'rules' => ScoringEngine::defaults()]);
        $this->deleteJson("/api/v1/presets/{$preset->id}")->assertUnauthorized();
        $this->account('referee');
        $this->deleteJson("/api/v1/presets/{$preset->id}")->assertForbidden();
        $this->assertDatabaseHas('scoring_presets', ['id' => $preset->id]);
    }

    public function test_admin_can_add_court_and_names_are_validated(): void
    {
        $this->account();
        $response = $this->postJson('/api/v1/courts', ['venue_id' => Venue::firstOrCreate(['name' => 'Venue Utama'])->id, 'name' => '  Court   Utama  '])->assertCreated()->assertJsonPath('name', 'Court Utama');
        $id = $response->json('id');
        $this->getJson('/api/v1/board')->assertJsonPath('courts.0.id', $id);
        $this->getJson('/api/v1/data')->assertJsonPath('courts.0.name', 'Court Utama');
        foreach (['Court Utama', 'court utama', '   ', str_repeat('a', 101)] as $name) {
            $this->postJson('/api/v1/courts', ['venue_id' => Venue::firstOrCreate(['name' => 'Venue Utama'])->id, 'name' => $name])->assertUnprocessable();
        }
        $this->assertDatabaseCount('courts', 1);
    }

    public function test_admin_can_delete_empty_court_and_release_referee_assignment(): void
    {
        $referee = User::factory()->create(['role' => 'referee']);
        $court = Court::create(['venue_id' => Venue::firstOrCreate(['name' => 'Venue Utama'])->id, 'name' => 'Empty', 'referee_id' => $referee->id]);
        $this->account();
        $this->deleteJson("/api/v1/courts/{$court->id}")->assertOk();
        $this->assertDatabaseMissing('courts', ['id' => $court->id]);
        $this->assertDatabaseHas('users', ['id' => $referee->id]);
        $replacement = Court::create(['venue_id' => Venue::firstOrCreate(['name' => 'Venue Utama'])->id, 'name' => 'Replacement', 'referee_id' => $referee->id]);
        $this->getJson('/api/v1/board')->assertJsonCount(1, 'courts')->assertJsonPath('courts.0.id', $replacement->id);
        $this->deleteJson("/api/v1/courts/{$court->id}")->assertNotFound();
    }

    public function test_court_with_unfinished_matches_cannot_be_deleted(): void
    {
        $this->account();
        $match = $this->fixture();
        foreach (['scheduled', 'live', 'paused', 'review'] as $status) {
            $match->update(['status' => $status]);
            $this->deleteJson("/api/v1/courts/{$match->court_id}")->assertConflict();
            $this->assertDatabaseHas('courts', ['id' => $match->court_id]);
            $this->assertSame($match->court_id, $match->fresh()->court_id);
        }
    }

    public function test_deleting_court_preserves_finished_match_results(): void
    {
        $this->account();
        $match = $this->fixture();
        $state = $this->points((new ScoringEngine)->initial(), 0, 48);
        $match->update(['state' => $state, 'status' => 'finished', 'finished_at' => now()]);
        $this->deleteJson("/api/v1/courts/{$match->court_id}")->assertOk();
        $this->assertDatabaseCount('padel_matches', 1);
        $this->assertSame($state, $match->fresh()->state);
        $this->assertSame('finished', $match->fresh()->status);
        $this->assertNull($match->fresh()->court_id);
        $this->assertNull($match->fresh()->queue_position);
        $this->assertSame(1, $match->fresh()->version);
        $this->getJson('/api/v1/board')->assertJsonCount(0, 'courts');
    }

    public function test_only_admin_can_create_and_delete_courts(): void
    {
        $court = Court::create(['venue_id' => Venue::firstOrCreate(['name' => 'Venue Utama'])->id, 'name' => 'Protected']);
        $this->postJson('/api/v1/courts', ['name' => 'New'])->assertUnauthorized();
        $this->deleteJson("/api/v1/courts/{$court->id}")->assertUnauthorized();
        $this->account('referee');
        $this->postJson('/api/v1/courts', ['name' => 'New'])->assertForbidden();
        $this->deleteJson("/api/v1/courts/{$court->id}")->assertForbidden();
        $this->assertDatabaseCount('courts', 1);
    }

    public function test_admin_can_rename_referee_without_changing_account_or_assignment(): void
    {
        $referee = User::factory()->create(['role' => 'referee', 'password' => 'original-password', 'api_token' => hash('sha256', 'original-token'), 'token_expires_at' => now()->addDay()]);
        $court = Court::create(['name' => 'Court 1', 'referee_id' => $referee->id]);
        $original = $referee->fresh()->getAttributes();
        $this->account();
        $this->patchJson("/api/v1/referees/{$referee->id}", ['name' => '  Budi   Santoso  ', 'email' => 'changed@example.com', 'password' => 'changed-password', 'role' => 'admin'])->assertOk()->assertJsonPath('name', 'Budi Santoso')->assertJsonMissingPath('password')->assertJsonMissingPath('api_token');
        $updated = $referee->fresh();
        foreach (['email', 'password', 'api_token', 'token_expires_at', 'role'] as $field) {
            $this->assertSame($original[$field], $updated->getAttributes()[$field]);
        }
        $this->assertSame($referee->id, $court->fresh()->referee_id);
        $this->getJson('/api/v1/data')->assertJsonPath('referees.0.name', 'Budi Santoso');
        $this->withToken('original-token')->getJson('/api/v1/data')->assertOk()->assertJsonPath('user.name', 'Budi Santoso');
    }

    public function test_referee_name_is_validated_and_admin_accounts_cannot_be_renamed_here(): void
    {
        $referee = User::factory()->create(['role' => 'referee']);
        $admin = $this->account();
        foreach (['', '   ', str_repeat('a', 101)] as $name) {
            $this->patchJson("/api/v1/referees/{$referee->id}", ['name' => $name])->assertUnprocessable();
        }
        $this->patchJson("/api/v1/referees/{$admin->id}", ['name' => 'Not a referee'])->assertNotFound();
        $this->patchJson('/api/v1/referees/99999', ['name' => 'Missing'])->assertNotFound();
        $this->assertSame($referee->name, $referee->fresh()->name);
    }

    public function test_only_admin_can_rename_referees(): void
    {
        $referee = User::factory()->create(['role' => 'referee']);
        $this->patchJson("/api/v1/referees/{$referee->id}", ['name' => 'Blocked'])->assertUnauthorized();
        $this->account('referee');
        $this->patchJson("/api/v1/referees/{$referee->id}", ['name' => 'Blocked'])->assertForbidden();
        $this->assertSame($referee->name, $referee->fresh()->name);
    }

    private function upcoming(PadelMatch $current, string $name): PadelMatch
    {
        return PadelMatch::create(['tier_id' => $current->tier_id, 'group_name' => 'A', 'team_a' => "{$name} A", 'team_b' => "{$name} B", 'state' => (new ScoringEngine)->initial(), 'rules' => ScoringEngine::defaults()]);
    }

    public function test_board_next_match_follows_saved_queue_and_promotes_after_confirmation(): void
    {
        $this->account();
        $current = $this->fixture();
        $current->status = 'live';
        $current->save();
        $older = $this->upcoming($current, 'Older');
        $newer = $this->upcoming($current, 'Newer');
        $this->upcoming($current, 'Unassigned');
        $this->postJson("/api/v1/matches/{$newer->id}/assign", ['court_id' => $current->court_id])->assertOk()->assertJsonPath('queue_position', 2);
        $this->postJson("/api/v1/matches/{$older->id}/assign", ['court_id' => $current->court_id])->assertOk()->assertJsonPath('queue_position', 3);
        $this->getJson('/api/v1/board')->assertOk()->assertJsonPath('courts.0.match.id', $current->id)->assertJsonPath('courts.0.next_match.id', $newer->id)->assertJsonPath('courts.0.queue_count', 2)->assertJsonPath('courts.0.next_tier.name', 'Bronze');
        $this->postJson("/api/v1/matches/{$older->id}/reorder", ['direction' => 'up', 'version' => 1])->assertOk();
        $this->getJson('/api/v1/board')->assertJsonPath('courts.0.next_match.id', $older->id);
        $current->state = $this->points((new ScoringEngine)->initial(), 0, 48);
        $current->status = 'review';
        $current->save();
        $this->getJson('/api/v1/board')->assertJsonPath('courts.0.match.id', $current->id);
        $this->postJson("/api/v1/matches/{$current->id}/score", ['action' => 'confirm', 'version' => 0, 'command_id' => Str::uuid()->toString()])->assertOk();
        $this->getJson('/api/v1/board')->assertJsonPath('courts.0.match.id', $older->id)->assertJsonPath('courts.0.match.status', 'scheduled')->assertJsonPath('courts.0.next_match.id', $newer->id)->assertJsonPath('courts.0.queue_count', 1);
        $this->postJson("/api/v1/matches/{$older->id}/score", ['action' => 'start', 'server' => 0, 'version' => 2, 'command_id' => Str::uuid()->toString()])->assertOk();
        $this->getJson('/api/v1/board')->assertJsonPath('courts.0.match.id', $older->id)->assertJsonPath('courts.0.match.status', 'live')->assertJsonPath('courts.0.next_match.id', $newer->id);
    }

    public function test_queue_removal_reordering_permissions_and_stale_versions(): void
    {
        $this->account();
        $current = $this->fixture();
        $current->status = 'live';
        $current->save();
        $next = $this->upcoming($current, 'Next');
        $last = $this->upcoming($current, 'Last');
        $this->postJson("/api/v1/matches/{$next->id}/assign", ['court_id' => $current->court_id])->assertOk();
        $this->postJson("/api/v1/matches/{$last->id}/assign", ['court_id' => $current->court_id])->assertOk();
        $this->postJson("/api/v1/matches/{$next->id}/reorder", ['direction' => 'down', 'version' => 0])->assertConflict();
        $this->postJson("/api/v1/matches/{$current->id}/reorder", ['direction' => 'down', 'version' => 0])->assertConflict();
        $this->postJson("/api/v1/matches/{$next->id}/score", ['action' => 'start', 'server' => 0, 'version' => 1, 'command_id' => Str::uuid()->toString()])->assertConflict();
        $this->postJson("/api/v1/matches/{$next->id}/assign", ['court_id' => null])->assertOk()->assertJsonPath('queue_position', null);
        $this->getJson('/api/v1/board')->assertJsonPath('courts.0.next_match.id', $last->id)->assertJsonPath('courts.0.queue_count', 1);
        $this->postJson("/api/v1/matches/{$last->id}/assign", ['court_id' => null])->assertOk();
        $this->getJson('/api/v1/board')->assertJsonPath('courts.0.next_match', null)->assertJsonPath('courts.0.queue_count', 0);
        $this->account('referee');
        $this->postJson("/api/v1/matches/{$next->id}/assign", ['court_id' => $current->court_id])->assertForbidden();
        $this->postJson("/api/v1/matches/{$last->id}/reorder", ['direction' => 'up', 'version' => 2])->assertForbidden();
    }
}
