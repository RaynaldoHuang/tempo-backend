<?php

namespace App\Http\Controllers;

use App\Models\Tier;
use App\Services\TournamentReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TournamentReportController extends Controller
{
    public function __invoke(Request $request, TournamentReport $report): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403, 'Laporan hanya dapat diakses panitia.');
        $today = now('Asia/Jakarta')->toDateString();
        $filters = $request->validate([
            'tournament_id' => 'sometimes|integer|exists:tournaments,id',
            'tier_id' => 'sometimes|integer|exists:tiers,id',
            'period' => ['sometimes', Rule::in(['all', 'today', 'range'])],
            'start_date' => "required_if:period,range|date_format:Y-m-d|before_or_equal:{$today}",
            'end_date' => "required_if:period,range|date_format:Y-m-d|after_or_equal:start_date|before_or_equal:{$today}",
            'results' => 'sometimes|boolean',
            'standings' => 'sometimes|boolean',
            'bracket' => 'sometimes|boolean',
        ]);
        if (isset($filters['tier_id'], $filters['tournament_id'])) {
            abort_unless(Tier::whereKey($filters['tier_id'])->where('tournament_id', $filters['tournament_id'])->exists(), 422, 'Kategori tidak berada dalam turnamen yang dipilih.');
        }
        abort_unless(($filters['results'] ?? true) || ($filters['standings'] ?? true) || ($filters['bracket'] ?? true), 422, 'Pilih minimal satu isi laporan.');

        return response()->json(DB::transaction(fn (): array => $report->build($filters)))
            ->header('Cache-Control', 'private, no-store');
    }
}
