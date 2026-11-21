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

class KnockoutRulesTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'admin'): void
    {
        $token = Str::random(40);
        User::factory()->create(['role' => $role, 'api_token' => hash('sha256', $token), 'token_expires_at' => now()->addDay()]);
        $this->withToken($token);
    }

    private function tier(): Tier
    {
        $venue = Venue::firstOrCreate(['name' => 'Venue Utama']);
        $tournament = Tournament::create(['name' => 'Open', 'venue_id' => $venue->id]);
        $rules = [...ScoringEngine::defaults(), 'regular_sets' => 1];
        $tier = Tier::create(['tournament_id' => $tournament->id, 'name' => 'Bronze', 'format' => 'group', 'rules' => $rules, 'groups' => [['name' => 'A', 'teams' => ['A1', 'A2']], ['name' => 'B', 'teams' => ['B1', 'B2']]]]);
        foreach ($tier->groups as $group) {
            $state = (new ScoringEngine)->initial();
            $state['winner'] = 0;
            $state['sets'] = [['games' => [6, 0], 'kind' => 'regular', 'winner' => 0]];
            PadelMatch::create(['tier_id' => $tier->id, 'stage' => 'group', 'group_name' => $group['name'], 'team_a' => $group['teams'][0], 'team_b' => $group['teams'][1], 'rules' => $rules, 'state' => $state, 'status' => 'finished']);
        }

        return $tier;
    }

    private function revision(Tier $tier): string
    {
        return collect($this->getJson('/api/v1/data')->assertOk()->json('tiers'))->firstWhere('id', $tier->id)['edit_revision'];
    }

    private function confirmKnockout(Tier $tier): void
    {
        $this->postJson("/api/v1/tiers/{$tier->id}/knockout", ['revision' => (new TournamentProgression)->overview($tier->fresh())['revision']])->assertOk();
    }

    public function test_rules_can_be_set_before_confirmation_and_survive_qualification_changes(): void
    {
        $this->account();
        $tier = $this->tier();
        $groupMatches = PadelMatch::all()->toArray();
        $rules = [...$tier->rules, 'regular_sets' => 2];
        $this->postJson("/api/v1/tiers/{$tier->id}/knockout-rules", ['rules' => $rules, 'edit_revision' => $this->revision($tier)])->assertOk()->assertJsonPath('updated_matches', 0);
        $this->postJson("/api/v1/tiers/{$tier->id}/progression", ['qualifiers' => 1])->assertOk();
        $this->confirmKnockout($tier);
        $this->assertEquals($rules, PadelMatch::where('stage', 'knockout')->firstOrFail()->rules);
        $this->assertSame(1, $tier->fresh()->rules['regular_sets']);
        $this->assertSame($groupMatches, PadelMatch::where('stage', 'group')->get()->toArray());
    }

    public function test_changing_rules_preserves_live_match_and_updates_unstarted_and_future_rounds(): void
    {
        $this->account();
        $tier = $this->tier();
        $this->confirmKnockout($tier);
        $matches = PadelMatch::where('stage', 'knockout')->orderBy('id')->get();
        $matches[0]->update(['status' => 'live', 'started_at' => now()]);
        $before = $matches[0]->fresh()->toArray();
        $bracket = $tier->fresh()->knockout['rounds'];
        $rules = [...$tier->rules, 'regular_sets' => 2];
        $this->postJson("/api/v1/tiers/{$tier->id}/knockout-rules", ['rules' => $rules, 'edit_revision' => $this->revision($tier)])->assertOk()->assertJsonPath('updated_matches', 1);
        $this->assertSame($before, $matches[0]->fresh()->toArray());
        $this->assertSame($bracket, $tier->fresh()->knockout['rounds']);
        $this->assertEquals($rules, $matches[1]->fresh()->rules);
        $this->assertSame($matches[1]->version + 1, $matches[1]->fresh()->version);
        foreach ($matches as $match) {
            $state = $match->state;
            $state['winner'] = 0;
            $match->update(['status' => 'finished', 'state' => $state]);
        }
        (new TournamentProgression)->advance($tier->fresh());
        $final = PadelMatch::where('stage', 'knockout')->latest('id')->firstOrFail();
        $this->assertSame('Final · 1', $final->group_name);
        $this->assertEquals($rules, $final->rules);
        $state = (new ScoringEngine)->initial();
        for ($point = 0; $point < 24; $point++) {
            $state = (new ScoringEngine)->point($state, 0, $final->rules);
        }
        $this->assertCount(1, $state['sets']);
        $this->assertNull($state['winner']);
    }

    public function test_old_referee_version_is_rejected_after_rules_change(): void
    {
        $this->account();
        $tier = $this->tier();
        $this->confirmKnockout($tier);
        $match = PadelMatch::where('stage', 'knockout')->firstOrFail();
        $court = Court::create(['name' => 'Court 1', 'venue_id' => Tournament::findOrFail($tier->tournament_id)->venue_id]);
        $this->postJson("/api/v1/matches/{$match->id}/assign", ['court_id' => $court->id])->assertOk();
        $version = $match->fresh()->version;
        $this->postJson("/api/v1/tiers/{$tier->id}/knockout-rules", ['rules' => [...$tier->rules, 'regular_sets' => 2], 'edit_revision' => $this->revision($tier)])->assertOk();
        $this->postJson("/api/v1/matches/{$match->id}/score", ['action' => 'start', 'server' => 0, 'version' => $version, 'command_id' => (string) Str::uuid()])->assertConflict();
        $this->postJson("/api/v1/matches/{$match->id}/score", ['action' => 'start', 'server' => 0, 'version' => $match->fresh()->version, 'command_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('rules.regular_sets', 2);
    }

    public function test_rules_require_admin_valid_input_and_current_revision(): void
    {
        $tier = $this->tier();
        $url = "/api/v1/tiers/{$tier->id}/knockout-rules";
        $this->postJson($url, [])->assertUnauthorized();
        $this->account('referee');
        $this->postJson($url, [])->assertForbidden();
        $this->account();
        $revision = $this->revision($tier);
        $this->postJson($url, ['rules' => [...$tier->rules, 'regular_sets' => 3], 'edit_revision' => $revision])->assertUnprocessable();
        $this->postJson($url, ['rules' => [...$tier->rules, 'regular_sets' => 2], 'edit_revision' => $revision])->assertOk();
        $this->postJson($url, ['rules' => $tier->rules, 'edit_revision' => $revision])->assertConflict();
        $this->assertSame(2, $tier->fresh()->knockout['rules']['regular_sets']);
    }

    public function test_paused_review_finished_and_previously_started_matches_keep_their_rules(): void
    {
        $this->account();
        $tier = $this->tier();
        $this->confirmKnockout($tier);
        $match = PadelMatch::where('stage', 'knockout')->firstOrFail();
        foreach (['paused', 'review', 'finished', 'scheduled'] as $status) {
            $match->update(['status' => $status, 'started_at' => now()]);
            $before = $match->fresh()->toArray();
            $this->postJson("/api/v1/tiers/{$tier->id}/knockout-rules", ['rules' => [...$tier->rules, 'regular_sets' => 2], 'edit_revision' => $this->revision($tier)])->assertOk();
            $this->assertSame($before, $match->fresh()->toArray());
        }
    }

    public function test_direct_knockout_manual_redraw_keeps_custom_rules(): void
    {
        $this->account();
        $tier = $this->tier();
        PadelMatch::where('tier_id', $tier->id)->delete();
        $tier->update(['format' => 'knockout', 'groups' => [['name' => 'Peserta', 'teams' => ['A1', 'A2', 'B1', 'B2']]]]);
        $rules = [...$tier->rules, 'regular_sets' => 2];
        $this->postJson("/api/v1/tiers/{$tier->id}/knockout-rules", ['rules' => $rules, 'edit_revision' => $this->revision($tier)])->assertOk();
        $preview = (new TournamentProgression)->overview($tier->fresh());
        $this->postJson("/api/v1/tiers/{$tier->id}/draw", ['revision' => $preview['draw_revision'], 'slots' => ['B1', 'A2', 'B2', 'A1']])->assertOk();
        $this->confirmKnockout($tier);
        foreach (PadelMatch::where('stage', 'knockout')->get() as $match) {
            $this->assertEquals($rules, $match->rules);
        }
    }
}
