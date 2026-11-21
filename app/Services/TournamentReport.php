<?php

namespace App\Services;

use App\Models\Court;
use App\Models\PadelMatch;
use App\Models\Tier;
use App\Models\Tournament;
use App\Models\Venue;
use Carbon\CarbonImmutable;

class TournamentReport
{
    public function build(array $filters): array
    {
        $generated = now('Asia/Jakarta')->toImmutable();
        $period = $filters['period'] ?? 'all';
        $start = $period === 'today' ? $generated->startOfDay() : ($period === 'range' ? CarbonImmutable::parse($filters['start_date'], 'Asia/Jakarta')->startOfDay() : null);
        $end = $period === 'range' ? CarbonImmutable::parse($filters['end_date'], 'Asia/Jakarta')->endOfDay()->min($generated) : $generated;
        $sections = ['results' => (bool) ($filters['results'] ?? true), 'standings' => (bool) ($filters['standings'] ?? true), 'bracket' => (bool) ($filters['bracket'] ?? true)];
        $tiers = Tier::when(isset($filters['tournament_id']), fn ($query) => $query->where('tournament_id', $filters['tournament_id']))
            ->when(isset($filters['tier_id']), fn ($query) => $query->whereKey($filters['tier_id']))->orderBy('tournament_id')->orderBy('id')->get();
        $tournaments = Tournament::whereIn('id', $tiers->pluck('tournament_id'))->get()->keyBy('id');
        $venues = Venue::whereIn('id', $tournaments->pluck('venue_id'))->get()->keyBy('id');
        $courts = Court::all()->keyBy('id');
        $matches = PadelMatch::whereIn('tier_id', $tiers->pluck('id'))->orderBy('id')->get()->groupBy('tier_id');
        $totals = ['total' => 0, 'finished' => 0, 'live' => 0, 'paused' => 0, 'review' => 0, 'scheduled' => 0];
        $categories = [];
        foreach ($tiers as $tier) {
            $all = $matches->get($tier->id, collect());
            $knockoutOrder = [];
            foreach ($tier->knockout['rounds'] ?? [] as $r => $round) {
                foreach ($round['matches'] as $i => $node) {
                    if ($node['match_id']) {
                        $knockoutOrder[$node['match_id']] = [$r, $i];
                    }
                }
            }
            $groupOrder = array_flip(array_column($tier->groups, 'name'));
            $selected = $all->filter(function (PadelMatch $match) use ($start, $end): bool {
                if ($start === null) {
                    return true;
                }
                $date = $match->finished_at ?? $match->started_at;

                return $date !== null && $date->betweenIncluded($start, $end);
            })->sortBy(fn (PadelMatch $match): array => $match->stage === 'knockout'
                ? [1, ...($knockoutOrder[$match->id] ?? [999, 999]), $match->id]
                : [0, $groupOrder[$match->group_name] ?? 999, $match->id, $match->id]);
            $summary = ['total' => $selected->count(), 'finished' => 0, 'live' => 0, 'paused' => 0, 'review' => 0, 'scheduled' => 0];
            foreach ($selected as $match) {
                if (array_key_exists($match->status, $summary)) {
                    $summary[$match->status]++;
                }
            }
            foreach ($summary as $key => $count) {
                $totals[$key] += $count;
            }
            $publicMatch = fn (PadelMatch $match): array => [
                'id' => $match->id, 'stage' => $match->stage, 'group_name' => $match->group_name,
                'team_a' => $match->team_a, 'team_b' => $match->team_b, 'status' => $match->status,
                'court_name' => $courts->get($match->court_id)?->name, 'rules' => $match->rules, 'state' => $match->state,
                'started_at' => $match->started_at?->toIso8601String(), 'finished_at' => $match->finished_at?->toIso8601String(),
                'winner' => $match->status === 'finished' && in_array($match->state['winner'], [0, 1], true) ? [$match->team_a, $match->team_b][$match->state['winner']] : null,
            ];
            $rounds = ($tier->knockout['confirmed_at'] ?? null) ? ($tier->knockout['rounds'] ?? []) : [];
            $final = $rounds ? $rounds[count($rounds) - 1]['matches'][0] : null;
            $finalMatch = $final ? $all->firstWhere('id', $final['match_id']) : null;
            $champion = $final && ($final['bye'] || $finalMatch?->status === 'finished') ? $final['winner'] : null;
            $bracket = [];
            if ($sections['bracket']) {
                foreach ($rounds as $r => $round) {
                    $nodes = [];
                    foreach ($round['matches'] as $i => $node) {
                        $match = $all->firstWhere('id', $node['match_id']);
                        $nodes[] = [...$node, 'match' => $match ? $publicMatch($match) : null, 'court_name' => $match ? $courts->get($match->court_id)?->name : $courts->get($tier->knockout['schedule']["{$r}:{$i}"] ?? null)?->name];
                    }
                    $bracket[] = ['name' => $round['name'], 'matches' => $nodes];
                }
            }
            $overview = $sections['standings'] && $tier->format === 'group' ? (new TournamentProgression)->overview($tier) : null;
            $tournament = $tournaments->get($tier->tournament_id);
            $categories[] = [
                'id' => $tier->id, 'name' => $tier->name, 'format' => $tier->format,
                'tournament' => $tournament?->name, 'venue' => $venues->get($tournament?->venue_id)?->name,
                'participants' => array_sum(array_map(fn (array $group): int => count($group['teams']), $tier->groups)),
                'summary' => $summary, 'total_matches' => $all->count(), 'champion' => $champion,
                'rules' => $tier->rules, 'knockout_rules' => $tier->knockout['rules'] ?? $tier->rules,
                'matches' => $sections['results'] ? $selected->map($publicMatch)->values()->all() : [],
                'standings' => $overview ? ['complete' => $overview['complete'], 'confirmed' => $overview['confirmed_at'] !== null, 'qualifiers' => $overview['qualifiers'], 'groups' => $overview['groups']] : null,
                'bracket' => $bracket, 'bracket_confirmed' => (bool) ($tier->knockout['confirmed_at'] ?? false),
            ];
        }

        return ['generated_at' => $generated->toIso8601String(), 'timezone' => 'Asia/Jakarta', 'period' => $period, 'start_date' => $start?->toDateString(), 'end_date' => $end->toDateString(), 'sections' => $sections, 'summary' => $totals, 'categories' => $categories];
    }
}
