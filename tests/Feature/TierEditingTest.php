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

class TierEditingTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'admin'): void
    {
        $token = Str::random(40);
        User::factory()->create(['role' => $role, 'api_token' => hash('sha256', $token), 'token_expires_at' => now()->addDay()]);
        $this->withToken($token);
    }

    private function createTier(string $format = 'group'): Tier
    {
        $tournament = Tournament::create(['name' => 'Open', 'venue_id' => Venue::firstOrFail()->id]);
        $response = $this->postJson('/api/v1/tiers', ['name' => 'Bronze', 'tournament_id' => $tournament->id, 'format' => $format, 'draw_mode' => 'random', 'groups' => [['name' => 'Pool Alpha', 'teams' => ['Andi / Budi', 'Candra / Dedi', 'Eko / Fajar']]], 'rules' => ScoringEngine::defaults()])->assertCreated();

        return Tier::findOrFail($response->json('id'));
    }

    private function payload(Tier $tier, array $changes = []): array
    {
        $row = collect($this->getJson('/api/v1/data')->assertOk()->json('tiers'))->firstWhere('id', $tier->id);

        return [...['name' => $tier->name, 'tournament_id' => $tier->tournament_id, 'format' => $tier->format, 'groups' => $tier->groups, 'rules' => $tier->rules, 'draw_mode' => $tier->knockout['draw_mode'] ?? 'ordered', 'edit_revision' => $row['edit_revision']], ...$changes];
    }

    public function test_name_and_rules_update_preserves_scheduled_matches_and_court_queue(): void
    {
        $this->account();
        $tier = $this->createTier();
        $matches = PadelMatch::where('tier_id', $tier->id)->get();
        $court = Court::create(['name' => 'Court 1', 'venue_id' => Venue::firstOrFail()->id]);
        $this->postJson("/api/v1/matches/{$matches[0]->id}/assign", ['court_id' => $court->id])->assertOk();
        $rules = [...$tier->rules, 'deuce_rule' => 'one_advantage'];
        $this->patchJson("/api/v1/tiers/{$tier->id}", $this->payload($tier, ['name' => 'Bronze Men', 'rules' => $rules]))->assertOk()->assertJsonPath('name', 'Bronze Men');
        $this->assertSame($matches->pluck('id')->all(), PadelMatch::where('tier_id', $tier->id)->pluck('id')->all());
        $this->assertSame($court->id, $matches[0]->fresh()->court_id);
        $this->assertSame(1, $matches[0]->fresh()->queue_position);
        foreach ($matches as $match) {
            $this->assertSame('one_advantage', $match->fresh()->rules['deuce_rule']);
        }
    }

    public function test_participant_changes_rebuild_only_unstarted_tier_schedule(): void
    {
        $this->account();
        $tier = $this->createTier();
        $other = $this->createTier();
        $ids = PadelMatch::where('tier_id', $tier->id)->pluck('id');
        $court = Court::create(['name' => 'Court 1', 'venue_id' => Venue::firstOrFail()->id]);
        $this->postJson("/api/v1/matches/{$ids[0]}/assign", ['court_id' => $court->id])->assertOk();
        $groups = [['name' => 'Pool Alpha', 'teams' => ['Andi / Budi', 'Candra / Dedi']], ['name' => 'Pool Beta', 'teams' => ['Eko / Fajar', 'Gilang / Hadi']]];
        $this->patchJson("/api/v1/tiers/{$tier->id}", $this->payload($tier, ['groups' => $groups]))->assertOk();
        $this->assertSame(0, PadelMatch::whereIn('id', $ids)->count());
        $matches = PadelMatch::where('tier_id', $tier->id)->get();
        $this->assertCount(2, $matches);
        $this->assertTrue($matches->every(fn (PadelMatch $match): bool => $match->court_id === null && $match->queue_position === null));
        $this->assertSame(3, PadelMatch::where('tier_id', $other->id)->count());
    }

    public function test_changing_tournament_and_format_rebuilds_knockout_for_the_new_venue(): void
    {
        $this->account();
        $tier = $this->createTier();
        $court = Court::create(['name' => 'Old Court', 'venue_id' => Venue::firstOrFail()->id]);
        $match = PadelMatch::where('tier_id', $tier->id)->first();
        $this->postJson("/api/v1/matches/{$match->id}/assign", ['court_id' => $court->id])->assertOk();
        $venue = Venue::create(['name' => 'Other venue']);
        $tournament = Tournament::create(['name' => 'Other Open', 'venue_id' => $venue->id]);
        $this->patchJson("/api/v1/tiers/{$tier->id}", $this->payload($tier, ['tournament_id' => $tournament->id, 'format' => 'knockout', 'draw_mode' => 'ordered']))->assertOk()->assertJsonPath('format', 'knockout');
        $this->assertSame(0, PadelMatch::where('tier_id', $tier->id)->count());
        $overview = (new TournamentProgression)->overview($tier->fresh());
        $this->postJson("/api/v1/tiers/{$tier->id}/knockout", ['revision' => $overview['revision']])->assertOk();
        $created = PadelMatch::where('tier_id', $tier->id)->first();
        $this->assertSame('knockout', $created->stage);
        $this->assertNull($created->court_id);
        $this->postJson("/api/v1/matches/{$created->id}/assign", ['court_id' => $court->id])->assertUnprocessable();
    }

    public function test_started_tier_only_allows_name_changes_and_preserves_scoring_history(): void
    {
        $this->account();
        $tier = $this->createTier();
        $match = PadelMatch::where('tier_id', $tier->id)->first();
        $court = Court::create(['name' => 'Court 1', 'venue_id' => Venue::firstOrFail()->id]);
        $this->postJson("/api/v1/matches/{$match->id}/assign", ['court_id' => $court->id])->assertOk();
        $this->postJson("/api/v1/matches/{$match->id}/score", ['action' => 'start', 'server' => 0, 'version' => $match->fresh()->version, 'command_id' => (string) Str::uuid()])->assertOk();
        $this->postJson("/api/v1/matches/{$match->id}/score", ['action' => 'point', 'team' => 0, 'version' => $match->fresh()->version, 'command_id' => (string) Str::uuid()])->assertOk();
        $payload = $this->payload($tier, ['rules' => [...$tier->rules, 'deuce_rule' => 'one_advantage']]);
        $this->patchJson("/api/v1/tiers/{$tier->id}", $payload)->assertConflict();
        $this->patchJson("/api/v1/tiers/{$tier->id}", ['name' => 'Renamed live tier', 'edit_revision' => $payload['edit_revision']])->assertOk();
        $this->assertSame([1, 0], $match->fresh()->state['points']);
        $this->assertSame($court->id, $match->fresh()->court_id);
        $this->assertDatabaseCount('score_events', 2);
        $this->assertSame($tier->rules, $tier->fresh()->rules);
    }

    public function test_random_knockout_rule_edit_keeps_draw_and_rejects_stale_confirmation(): void
    {
        $this->account();
        $tier = $this->createTier('knockout');
        $overview = (new TournamentProgression)->overview($tier);
        $this->patchJson("/api/v1/tiers/{$tier->id}", $this->payload($tier, ['rules' => [...$tier->rules, 'games_to_win' => 4]]))->assertOk();
        $updated = (new TournamentProgression)->overview($tier->fresh());
        $this->assertSame($overview['rounds'], $updated['rounds']);
        $this->assertSame('random', $tier->fresh()->knockout['draw_mode']);
        $this->postJson("/api/v1/tiers/{$tier->id}/knockout", ['revision' => $overview['revision']])->assertConflict();
        $this->postJson("/api/v1/tiers/{$tier->id}/knockout", ['revision' => $updated['revision']])->assertOk();
        $this->patchJson("/api/v1/tiers/{$tier->id}", $this->payload($tier->fresh()))->assertConflict();
    }

    public function test_stale_edits_invalid_data_and_non_admin_edits_are_rejected(): void
    {
        $this->account();
        $tier = $this->createTier();
        $payload = $this->payload($tier);
        $this->patchJson("/api/v1/tiers/{$tier->id}", ['name' => 'First edit', 'edit_revision' => $payload['edit_revision']])->assertOk();
        $this->patchJson("/api/v1/tiers/{$tier->id}", $payload)->assertConflict();
        $invalid = $this->payload($tier->fresh(), ['groups' => [['name' => 'Bad', 'teams' => ['Duplicate', 'Duplicate']]]]);
        $this->patchJson("/api/v1/tiers/{$tier->id}", $invalid)->assertUnprocessable();
        $this->assertSame(3, PadelMatch::where('tier_id', $tier->id)->count());
        $this->account('referee');
        $this->patchJson("/api/v1/tiers/{$tier->id}", $payload)->assertForbidden();
        $this->withToken('')->patchJson("/api/v1/tiers/{$tier->id}", $payload)->assertUnauthorized();
    }
}
