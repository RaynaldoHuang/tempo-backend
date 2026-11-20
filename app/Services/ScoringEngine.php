<?php

namespace App\Services;

class ScoringEngine
{
    public static function defaults(): array
    {
        return ['regular_sets' => 2, 'games_to_win' => 6, 'golden_point' => false, 'tiebreak_points' => 7, 'tiebreak_win_by_two' => true, 'super_points' => 10];
    }

    public function initial(int $server = 0): array
    {
        return ['points' => [0, 0], 'games' => [0, 0], 'sets' => [], 'mode' => 'regular', 'server' => $server, 'game_server' => $server, 'winner' => null];
    }

    public function point(array $state, int $team, array $rules): array
    {
        if ($state['winner'] !== null) {
            return $state;
        }
        $other = 1 - $team;
        $state['points'][$team]++;
        [$a, $b] = [$state['points'][$team], $state['points'][$other]];
        if ($state['mode'] !== 'regular') {
            $target = $state['mode'] === 'super' ? $rules['super_points'] : $rules['tiebreak_points'];
            $winByTwo = $state['mode'] === 'super' || ($rules['tiebreak_win_by_two'] ?? true);
            if ($a >= $target && (! $winByTwo || $a - $b >= 2)) {
                $points = $state['points'];
                $state['server'] = ($state['game_server'] + 1) % 4;
                if ($state['mode'] === 'super') {
                    $state['sets'][] = ['games' => $points, 'kind' => 'super', 'winner' => $team];
                    $state['winner'] = $team;

                    return $state;
                }
                $state['games'][$team]++;

                return $this->finishSet($state, $team, $rules, $points);
            }
            $played = array_sum($state['points']);
            $state['server'] = ($state['game_server'] + intdiv($played + 1, 2)) % 4;

            return $state;
        }
        if ($a >= 4 && ($a - $b >= 2 || ($rules['golden_point'] && $b >= 3))) {
            $state['games'][$team]++;
            $state['points'] = [0, 0];
            $state['server'] = ($state['game_server'] + 1) % 4;
            $state['game_server'] = $state['server'];
            [$ga, $gb] = [$state['games'][$team], $state['games'][$other]];
            if ($ga >= $rules['games_to_win'] && $ga - $gb >= 2) {
                return $this->finishSet($state, $team, $rules);
            }
            $tiebreakAt = $rules['tiebreak_at'] ?? $rules['games_to_win'];
            if ($ga === $tiebreakAt && $gb === $tiebreakAt) {
                $state['mode'] = 'tiebreak';
            }
        }

        return $state;
    }

    private function finishSet(array $state, int $team, array $rules, ?array $tiebreak = null): array
    {
        $state['sets'][] = ['games' => $state['games'], 'kind' => 'regular', 'winner' => $team, 'tiebreak' => $tiebreak];
        $wins = count(array_filter($state['sets'], fn (array $set): bool => $set['winner'] === $team));
        $state['points'] = [0, 0];
        $state['games'] = [0, 0];
        $state['game_server'] = $state['server'];
        if ($wins >= $rules['regular_sets']) {
            $state['winner'] = $team;
        } else {
            $state['mode'] = count($state['sets']) === 2 ? 'super' : 'regular';
        }

        return $state;
    }
}
