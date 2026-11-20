<?php

namespace App\Services;

use App\Models\CourtReservation;
use App\Models\PadelMatch;
use Illuminate\Support\Collection;

class CourtQueue
{
    public function pending(int $courtId): Collection
    {
        return collect(PadelMatch::where('court_id', $courtId)->where('status', 'scheduled')->get()->all())
            ->concat(CourtReservation::where('court_id', $courtId)->get())
            ->sortBy(fn ($item): array => [$item->queue_position ?? $item->id, $item instanceof CourtReservation ? 1 : 0, $item->id])->values();
    }

    public function nextPosition(int $courtId): int
    {
        return max(PadelMatch::where('court_id', $courtId)->max('queue_position') ?? 0, CourtReservation::where('court_id', $courtId)->max('queue_position') ?? 0) + 1;
    }

    public function move(PadelMatch|CourtReservation $item, string $direction): void
    {
        $queue = $this->pending($item->court_id);
        $index = $queue->search(fn ($entry): bool => $entry::class === $item::class && $entry->id === $item->id);
        abort_if($index === false, 409, 'Antrean berubah. Periksa urutan terbaru.');
        $target = $index + ($direction === 'up' ? -1 : 1);
        abort_unless($target >= 0 && $target < $queue->count(), 409, 'Pertandingan sudah berada di batas antrean.');
        $other = $queue[$target];
        $position = $item->queue_position;
        $item->queue_position = $other->queue_position;
        $other->queue_position = $position;
        $item->version++;
        $other->version++;
        $item->save();
        $other->save();
    }
}
