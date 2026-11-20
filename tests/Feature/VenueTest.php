<?php

namespace Tests\Feature;

use App\Models\Court;
use App\Models\PadelMatch;
use App\Models\Tier;
use App\Models\Tournament;
use App\Models\User;
use App\Models\Venue;
use App\Services\ScoringEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VenueTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'admin'): void
    {
        $token = Str::random(40);
        User::factory()->create(['role' => $role, 'api_token' => hash('sha256', $token), 'token_expires_at' => now()->addDay()]);
        $this->withToken($token);
    }

    private function matchAt(Venue $venue): PadelMatch
    {
        $tournament = Tournament::create(['name' => 'Open '.$venue->name, 'venue_id' => $venue->id, 'venue' => $venue->name]);
        $tier = Tier::create(['name' => 'Bronze', 'tournament_id' => $tournament->id, 'format' => 'group', 'groups' => [['name' => 'A', 'teams' => ['One', 'Two']]], 'rules' => ScoringEngine::defaults()]);

        return PadelMatch::create(['tier_id' => $tier->id, 'group_name' => 'A', 'team_a' => 'One', 'team_b' => 'Two', 'state' => (new ScoringEngine)->initial(), 'rules' => $tier->rules]);
    }

    public function test_admin_can_create_venue_and_tournament_requires_a_saved_venue(): void
    {
        $this->account();
        $venue = $this->postJson('/api/v1/venues', ['name' => '  Padel   Club  '])->assertCreated()->assertJsonPath('name', 'Padel Club')->json('id');
        $this->postJson('/api/v1/venues', ['name' => 'padel club'])->assertUnprocessable();
        $this->postJson('/api/v1/venues', ['name' => '   '])->assertUnprocessable();
        $this->postJson('/api/v1/tournaments', ['name' => 'Open', 'venue' => 'Free text'])->assertUnprocessable();
        $this->postJson('/api/v1/tournaments', ['name' => 'Open', 'venue_id' => 99999])->assertUnprocessable();
        $this->postJson('/api/v1/tournaments', ['name' => 'Open', 'venue_id' => $venue])->assertCreated()->assertJsonPath('venue_id', $venue)->assertJsonPath('venue', 'Padel Club');
        $this->getJson('/api/v1/data')->assertJsonFragment(['id' => $venue, 'name' => 'Padel Club']);
    }

    public function test_court_names_are_unique_within_each_venue_and_venue_is_required(): void
    {
        $this->account();
        $a = Venue::create(['name' => 'Alpha']);
        $b = Venue::create(['name' => 'Beta']);
        $this->postJson('/api/v1/courts', ['name' => 'Court 1'])->assertUnprocessable();
        $this->postJson('/api/v1/courts', ['name' => 'Court 1', 'venue_id' => $a->id])->assertCreated();
        $this->postJson('/api/v1/courts', ['name' => 'court 1', 'venue_id' => $a->id])->assertUnprocessable();
        $this->postJson('/api/v1/courts', ['name' => 'Court 1', 'venue_id' => $b->id])->assertCreated();
        $this->assertDatabaseCount('courts', 2);
    }

    public function test_match_can_only_be_assigned_to_its_tournament_venue(): void
    {
        $this->account();
        $a = Venue::create(['name' => 'Alpha']);
        $b = Venue::create(['name' => 'Beta']);
        $match = $this->matchAt($a);
        $correct = Court::create(['name' => 'Court 1', 'venue_id' => $a->id]);
        $wrong = Court::create(['name' => 'Court 1', 'venue_id' => $b->id]);
        $url = "/api/v1/matches/{$match->id}/assign";
        $this->postJson($url, ['court_id' => $wrong->id])->assertUnprocessable();
        $this->assertNull($match->fresh()->court_id);
        $this->postJson($url, ['court_id' => $correct->id])->assertOk();
        $this->patchJson("/api/v1/courts/{$correct->id}", ['venue_id' => $b->id])->assertConflict();
        $this->patchJson("/api/v1/courts/{$correct->id}", ['name' => 'Court Utama', 'venue_id' => (string) $a->id])->assertOk()->assertJsonPath('name', 'Court Utama');
        $this->postJson($url, ['court_id' => null])->assertOk();
        $this->patchJson("/api/v1/courts/{$correct->id}", ['venue_id' => $b->id])->assertOk()->assertJsonPath('venue_id', $b->id);
    }

    public function test_public_tv_filters_courts_tournaments_and_slides_by_venue(): void
    {
        $a = Venue::create(['name' => 'Alpha']);
        $b = Venue::create(['name' => 'Beta']);
        $matchA = $this->matchAt($a);
        $this->matchAt($b);
        foreach (range(1, 7) as $number) {
            Court::create(['name' => "Court {$number}", 'venue_id' => $a->id]);
        }
        Court::create(['name' => 'Court 1', 'venue_id' => $b->id]);
        $this->getJson('/api/v1/board')->assertJsonCount(8, 'courts')->assertJsonCount(0, 'standings');
        $this->getJson("/api/v1/board?venue_id={$a->id}")->assertOk()->assertJsonCount(7, 'courts')->assertJsonCount(1, 'tournaments')->assertJsonCount(1, 'standings')->assertJsonPath('courts.0.venue_name', 'Alpha')->assertJsonPath('standings.0.tier_id', $matchA->tier_id);
        $this->getJson('/api/v1/board?venue_id=99999')->assertUnprocessable();
    }

    public function test_referee_cannot_create_venue_or_move_court(): void
    {
        $venue = Venue::firstOrFail();
        $court = Court::create(['name' => 'Court', 'venue_id' => $venue->id]);
        $this->postJson('/api/v1/venues', ['name' => 'No'])->assertUnauthorized();
        $this->account('referee');
        $this->postJson('/api/v1/venues', ['name' => 'No'])->assertForbidden();
        $this->patchJson("/api/v1/courts/{$court->id}", ['venue_id' => $venue->id])->assertForbidden();
    }

    public function test_venue_migration_rollback_keeps_tournaments_and_match_history(): void
    {
        $venue = Venue::firstOrFail();
        $match = $this->matchAt($venue);
        $court = Court::create(['name' => 'Court 1', 'venue_id' => $venue->id]);
        $match->update(['court_id' => $court->id]);
        $migration = require database_path('migrations/2026_11_21_014443_create_venues_and_link_courts_and_tournaments.php');
        $migration->down();
        $this->assertDatabaseCount('tournaments', 1);
        $this->assertDatabaseCount('tiers', 1);
        $this->assertDatabaseCount('padel_matches', 1);
        $this->assertSame($court->id, $match->fresh()->court_id);
        $migration->up();
        $this->assertSame($court->id, $match->fresh()->court_id);
    }

    public function test_migration_preserves_legacy_venues_matches_and_active_court_assignment(): void
    {
        $migration = require database_path('migrations/2026_11_21_014443_create_venues_and_link_courts_and_tournaments.php');
        $migration->down();
        $alpha = Tournament::create(['name' => 'Old Alpha', 'venue' => 'Alpha']);
        $beta = Tournament::create(['name' => 'Old Beta', 'venue' => 'Beta']);
        $duplicate = Tournament::create(['name' => 'Another Alpha', 'venue' => '  alpha  ']);
        $referee = User::factory()->create(['role' => 'referee']);
        $court = Court::create(['name' => 'Court 1', 'referee_id' => $referee->id]);
        $empty = Court::create(['name' => 'Court 2']);
        $matches = [];
        foreach ([$alpha, $beta] as $index => $tournament) {
            $tier = Tier::create(['name' => 'Old', 'tournament_id' => $tournament->id, 'format' => 'direct', 'groups' => [], 'rules' => ScoringEngine::defaults()]);
            $matches[] = PadelMatch::create(['tier_id' => $tier->id, 'court_id' => $court->id, 'queue_position' => $index + 1, 'group_name' => 'Old', 'team_a' => 'One', 'team_b' => 'Two', 'status' => $index === 0 ? 'finished' : 'live', 'state' => (new ScoringEngine)->initial(), 'rules' => $tier->rules]);
        }
        $migration->up();
        $this->assertSame($alpha->fresh()->venue_id, $duplicate->fresh()->venue_id);
        $this->assertSame($beta->fresh()->venue_id, $court->fresh()->venue_id);
        $this->assertSame($referee->id, $court->fresh()->referee_id);
        $this->assertSame($court->id, $matches[1]->fresh()->court_id);
        $this->assertSame($alpha->fresh()->venue_id, Court::findOrFail($matches[0]->fresh()->court_id)->venue_id);
        $this->assertSame(1, $matches[0]->fresh()->queue_position);
        $this->assertSame('finished', $matches[0]->fresh()->status);
        $this->assertSame('live', $matches[1]->fresh()->status);
        $this->assertSame('Venue Utama', Venue::findOrFail($empty->fresh()->venue_id)->name);
        $this->assertDatabaseCount('padel_matches', 2);
        $this->assertDatabaseCount('courts', 3);
    }
}
