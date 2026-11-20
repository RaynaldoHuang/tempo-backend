<?php

namespace App\Services;

use App\Models\PadelMatch;
use App\Models\Tier;

class TournamentProgression
{
    public function overview(Tier $tier): array
    {
        $config = $tier->knockout ?? [];
        if ($tier->format === 'knockout') {
            $rounds = $config['rounds'] ?? $this->directDraw($tier);

            return ['revision' => hash('sha256', json_encode([$tier->groups, $rounds])), 'tie_orders' => (object) [], 'qualifiers' => 0, 'complete' => true, 'ready' => true, 'finished' => 0, 'total' => 0, 'groups' => [], 'rounds' => $rounds, 'confirmed_at' => $config['confirmed_at'] ?? null];
        }
        $qualifiers = $config['qualifiers'] ?? 2;
        $matches = PadelMatch::where('tier_id', $tier->id)->where('stage', 'group')->orderBy('id')->get();
        $complete = $matches->isNotEmpty() && $matches->every(fn (PadelMatch $match): bool => $match->status === 'finished' && in_array($match->state['winner'], [0, 1], true));
        $groups = [];
        foreach ($tier->groups as $group) {
            $rows = [];
            foreach ($group['teams'] as $team) {
                $row = ['team' => $team, 'played' => 0, 'wins' => 0, 'sets' => 0, 'games' => 0];
                foreach ($matches->where('group_name', $group['name'])->where('status', 'finished') as $match) {
                    if (! in_array($team, [$match->team_a, $match->team_b], true)) {
                        continue;
                    }
                    $side = $match->team_a === $team ? 0 : 1;
                    $row['played']++;
                    $row['wins'] += $match->state['winner'] === $side ? 1 : 0;
                    foreach ($match->state['sets'] as $set) {
                        $row['sets'] += $set['winner'] === $side ? 1 : -1;
                        if ($set['kind'] !== 'super') {
                            $row['games'] += $set['games'][$side] - $set['games'][1 - $side];
                        }
                    }
                }
                $rows[] = $row;
            }
            usort($rows, fn (array $a, array $b): int => $b['wins'] <=> $a['wins'] ?: $b['sets'] <=> $a['sets'] ?: $b['games'] <=> $a['games']);
            $ties = [];
            $ranked = [];
            foreach (collect($rows)->groupBy(fn (array $row): string => "{$row['wins']}:{$row['sets']}:{$row['games']}") as $tied) {
                $block = $tied->all();
                $needsOrder = false;
                if (count($block) === 2) {
                    $head = $matches->where('group_name', $group['name'])->where('status', 'finished')->first(fn (PadelMatch $match): bool => in_array($match->team_a, array_column($block, 'team'), true) && in_array($match->team_b, array_column($block, 'team'), true));
                    if ($head && in_array($head->state['winner'], [0, 1], true)) {
                        $winner = $head->state['winner'] === 0 ? $head->team_a : $head->team_b;
                        usort($block, fn (array $a, array $b): int => ($b['team'] === $winner ? 1 : 0) <=> ($a['team'] === $winner ? 1 : 0));
                    } else {
                        $ties[] = array_column($block, 'team');
                        $needsOrder = true;
                    }
                } elseif (count($block) > 2) {
                    $ties[] = array_column($block, 'team');
                    $needsOrder = true;
                }
                $order = $config['tie_orders'][$group['name']] ?? [];
                if ($needsOrder && count($order) === count($rows)) {
                    usort($block, fn (array $a, array $b): int => array_search($a['team'], $order, true) <=> array_search($b['team'], $order, true));
                }
                array_push($ranked, ...$block);
            }
            $unresolved = count($ties) > 0 && ! isset($config['tie_orders'][$group['name']]);
            $groups[] = ['name' => $group['name'], 'rows' => $ranked, 'ties' => $ties, 'unresolved' => $unresolved];
        }
        $ready = $complete && ! collect($groups)->contains('unresolved', true);
        $rounds = $config['rounds'] ?? $this->draw($groups, $qualifiers);

        $revision = hash('sha256', json_encode([$qualifiers, $config['tie_orders'] ?? [], $matches->map(fn (PadelMatch $match): array => [$match->id, $match->status, $match->state])->all()]));

        return ['revision' => $revision, 'tie_orders' => $config['tie_orders'] ?? (object) [], 'qualifiers' => $qualifiers, 'complete' => $complete, 'ready' => $ready, 'finished' => $matches->where('status', 'finished')->count(), 'total' => $matches->count(), 'groups' => $groups, 'rounds' => $rounds, 'confirmed_at' => $config['confirmed_at'] ?? null];
    }

    private function draw(array $groups, int $qualifiers): array
    {
        $groupCount = count($groups);
        $size = 1;
        while ($size < $groupCount) {
            $size *= 2;
        }
        $entry = function (int $index, int $rank) use ($groups): array {
            return isset($groups[$index]) ? ['label' => ($rank === 0 ? 'Juara ' : 'Runner-up ').$groups[$index]['name'], 'team' => $groups[$index]['rows'][$rank]['team']] : ['label' => 'BYE', 'team' => null];
        };
        $slots = [];
        if ($qualifiers === 2 && $size > 1) {
            for ($parity = 0; $parity < 2; $parity++) {
                for ($i = $parity; $i < $size; $i += 2) {
                    $slots[] = $entry($i, 0);
                    $slots[] = $entry($i ^ 1, 1);
                }
            }
        } elseif ($qualifiers === 2) {
            $slots = [$entry(0, 0), $entry(0, 1)];
        } else {
            $seeds = [1, 2];
            while (count($seeds) < $size) {
                $sum = count($seeds) * 2 + 1;
                $seeds = array_merge(...array_map(fn (int $seed): array => [$seed, $sum - $seed], $seeds));
            }
            foreach ($seeds as $seed) {
                $slots[] = $entry($seed - 1, 0);
            }
        }

        return $this->rounds($slots);
    }

    private function directDraw(Tier $tier): array
    {
        $teams = $tier->groups[0]['teams'];
        $size = 2;
        while ($size < count($teams)) {
            $size *= 2;
        }
        $byeCount = $size - count($teams);
        $matchCount = intdiv($size, 2);
        $positions = [0];
        for ($step = intdiv($matchCount, 2); $step >= 1; $step = intdiv($step, 2)) {
            $positions = [...$positions, ...array_map(fn (int $position): int => $position + $step, $positions)];
        }
        $byePositions = array_flip(array_slice($positions, 0, $byeCount));
        $entry = fn (int $index): array => ['label' => 'Peserta '.($index + 1), 'team' => $teams[$index]];
        $slots = [];
        $next = $byeCount;
        for ($match = 0; $match < $matchCount; $match++) {
            if (isset($byePositions[$match])) {
                $slots[] = $entry($byePositions[$match]);
                $slots[] = ['label' => 'BYE', 'team' => null];
            } else {
                $slots[] = $entry($next++);
                $slots[] = $entry($next++);
            }
        }

        return $this->rounds($slots);
    }

    private function rounds(array $slots): array
    {
        $rounds = [];
        $count = intdiv(count($slots), 2);
        for ($round = 0; $count >= 1; $round++, $count = intdiv($count, 2)) {
            $nodes = [];
            for ($i = 0; $i < $count; $i++) {
                $nodes[] = ['a' => $round === 0 ? $slots[$i * 2] : ['label' => 'Pemenang R'.$round.' · '.($i * 2 + 1), 'team' => null], 'b' => $round === 0 ? $slots[$i * 2 + 1] : ['label' => 'Pemenang R'.$round.' · '.($i * 2 + 2), 'team' => null], 'match_id' => null, 'winner' => null, 'bye' => $round === 0 && ($slots[$i * 2]['team'] === null || $slots[$i * 2 + 1]['team'] === null)];
            }
            $rounds[] = ['name' => match ($count) {
                1 => 'Final', 2 => 'Semifinal', 4 => 'Perempat final', default => 'Babak '.($count * 2).' besar'
            }, 'matches' => $nodes];
        }

        return $rounds;
    }

    public function broadcast(Tier $tier, array $courtNames): array
    {
        $rounds = $tier->knockout['rounds'];
        $active = $rounds[count($rounds) - 1];
        foreach ($rounds as $round) {
            if (collect($round['matches'])->contains(fn (array $node): bool => $node['winner'] === null && ! $node['bye'])) {
                $active = $round;
                break;
            }
        }
        $matches = PadelMatch::where('tier_id', $tier->id)->where('stage', 'knockout')->get()->keyBy('id');
        $entries = [];
        foreach ($active['matches'] as $node) {
            $match = $matches->get($node['match_id']);
            $entries[] = ['team_a' => $node['a']['team'], 'team_b' => $node['b']['team'], 'label_a' => $node['a']['label'], 'label_b' => $node['b']['label'], 'winner' => $node['winner'], 'bye' => $node['bye'], 'status' => $match?->status ?? 'waiting', 'court_name' => $match ? ($courtNames[$match->court_id] ?? null) : null, 'sets' => array_column($match?->state['sets'] ?? [], 'games'), 'points' => $match?->state['points'] ?? [0, 0], 'games' => $match?->state['games'] ?? [0, 0], 'mode' => $match?->state['mode'] ?? 'regular'];
        }

        return ['tier_id' => $tier->id, 'tier_name' => $tier->name, 'tournament_name' => null, 'round_name' => $active['name'], 'champion' => $rounds[count($rounds) - 1]['matches'][0]['winner'], 'entries' => $entries];
    }

    public function advance(Tier $tier): void
    {
        $config = $tier->knockout;
        if (! ($config['confirmed_at'] ?? null)) {
            return;
        }
        $matches = PadelMatch::where('tier_id', $tier->id)->get()->keyBy('id');
        foreach ($config['rounds'] as $r => &$round) {
            foreach ($round['matches'] as $i => &$node) {
                $resolved = true;
                if ($r > 0) {
                    foreach (['a', 'b'] as $side => $key) {
                        $source = $config['rounds'][$r - 1]['matches'][$i * 2 + $side];
                        $node[$key]['team'] = $source['winner'];
                        $resolved = $resolved && ($source['winner'] !== null || $source['bye']);
                    }
                }
                if (! $resolved) {
                    continue;
                }
                if ($node['a']['team'] === null || $node['b']['team'] === null) {
                    $node['bye'] = true;
                    $node['winner'] = $node['a']['team'] ?? $node['b']['team'];

                    continue;
                }
                if (! $node['match_id']) {
                    $match = PadelMatch::create(['tier_id' => $tier->id, 'stage' => 'knockout', 'group_name' => $round['name'].' · '.($i + 1), 'team_a' => $node['a']['team'], 'team_b' => $node['b']['team'], 'rules' => $tier->rules, 'state' => (new ScoringEngine)->initial()]);
                    $node['match_id'] = $match->id;
                    $matches->put($match->id, $match);
                }
                $match = $matches->get($node['match_id']);
                if ($match->status === 'finished' && in_array($match->state['winner'], [0, 1], true)) {
                    $node['winner'] = $match->state['winner'] === 0 ? $match->team_a : $match->team_b;
                }
            }
            unset($node);
        }
        unset($round);
        $tier->update(['knockout' => $config]);
    }
}
