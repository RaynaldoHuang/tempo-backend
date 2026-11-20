<?php

namespace App\Http\Controllers;

use App\Models\Court;
use App\Models\CourtReservation;
use App\Models\PadelMatch;
use App\Models\ScoringPreset;
use App\Models\Tier;
use App\Models\Tournament;
use App\Models\User;
use App\Models\Venue;
use App\Services\CourtQueue;
use App\Services\ScoringEngine;
use App\Services\TournamentProgression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PadelController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $user = User::where('email', strtolower($data['email']))->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Email atau password salah.']);
        }
        $token = Str::random(80);
        $user->api_token = hash('sha256', $token);
        $user->token_expires_at = now()->addHours(24);
        $user->save();

        return response()->json(['token' => $token, 'user' => $user]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->api_token = null;
        $user->token_expires_at = null;
        $user->save();

        return response()->json(['message' => 'Logout berhasil.']);
    }

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate(['current_password' => 'required|string', 'password' => 'required|string|min:10|confirmed']);
        abort_unless(Hash::check($data['current_password'], $request->user()->password), 422, 'Password lama tidak sesuai.');
        $request->user()->password = $data['password'];
        $request->user()->save();

        return response()->json(['message' => 'Password diperbarui.']);
    }

    public function data(Request $request, TournamentProgression $progression): JsonResponse
    {
        $admin = $request->user()->role === 'admin';
        $courts = Court::orderBy('id')->get();
        $matches = PadelMatch::orderBy('id')->get();
        if (! $admin) {
            $assigned = $courts->where('referee_id', $request->user()->id)->pluck('id');
            $matches = $matches->whereIn('court_id', $assigned)->values();
        }

        return response()->json([
            'venues' => Venue::orderBy('name')->get(), 'user' => $request->user(), 'courts' => $courts, 'matches' => $matches,
            'tournaments' => Tournament::orderByDesc('id')->get(), 'tiers' => Tier::all()->map(fn (Tier $tier): Tier => $tier->setAttribute('edit_revision', $this->tierRevision($tier))),
            'presets' => ScoringPreset::all(),
            'knockout_schedule' => Tier::whereIn('format', ['group', 'knockout'])->get()->flatMap(fn (Tier $tier): array => $progression->scheduledFixtures($tier))->filter(fn (array $fixture): bool => $admin || $assigned->contains($fixture['court_id']))->values(),
            'referees' => $admin ? User::where('role', 'referee')->get(['id', 'name', 'email']) : [],
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function board(Request $request, TournamentProgression $progression): JsonResponse
    {
        $requestedVenueId = $request->validate(['venue_id' => 'sometimes|integer|exists:venues,id'])['venue_id'] ?? null;
        $venues = Venue::where('is_active', true)->orderBy('name')->get();
        $venueId = $venues->firstWhere('id', $requestedVenueId)?->id ?? $venues->first()?->id;
        if ($venueId === null) {
            return response()->json(['active_venue_id' => null, 'venues' => [], 'courts' => [], 'tournaments' => [], 'standings' => [], 'knockouts' => [], 'server_time' => now()->toIso8601String()]);
        }
        $courts = Court::when($venueId, fn ($query) => $query->where('venue_id', $venueId))->orderBy('id')->get()->map(function (Court $court) use ($venues, $progression): array {
            $matches = PadelMatch::where('court_id', $court->id)->whereIn('tier_id', Tier::whereIn('tournament_id', Tournament::where('venue_id', $court->venue_id)->select('id'))->select('id'))->queueOrder()->get();
            $pending = (new CourtQueue)->pending($court->id)->map(fn ($entry): ?PadelMatch => $entry instanceof CourtReservation ? $progression->reservationMatch($entry) : $entry)->filter()->values();
            $current = $matches->first(fn (PadelMatch $match): bool => in_array($match->status, ['live', 'paused', 'review']))
                ?? $pending->first()
                ?? $matches->where('status', 'finished')->sortByDesc('finished_at')->first();
            $next = $pending->first(fn (PadelMatch $match): bool => $match->id !== $current?->id);
            $tier = $current ? Tier::find($current->tier_id) : null;
            $nextTier = $next ? Tier::find($next->tier_id) : null;

            return [
                'id' => $court->id, 'name' => $court->name, 'venue_id' => $court->venue_id, 'venue_name' => $venues->firstWhere('id', $court->venue_id)?->name,
                'match' => $current,
                'tier' => $tier ? ['name' => $tier->name, 'tournament_id' => $tier->tournament_id] : null,
                'next_match' => $next,
                'next_tier' => $nextTier ? ['name' => $nextTier->name, 'tournament_id' => $nextTier->tournament_id] : null,
                'queue_count' => $pending->filter(fn (PadelMatch $match): bool => $match->id !== $current?->id)->count(),
            ];
        });

        $tournaments = Tournament::when($venueId, fn ($query) => $query->where('venue_id', $venueId))->orderByDesc('id')->get();
        $standings = [];
        $knockouts = [];
        if ($courts->count() <= 7) {
            foreach (Tier::whereIn('format', ['group', 'knockout'])->when($venueId, fn ($query) => $query->whereIn('tournament_id', $tournaments->pluck('id')))->orderBy('id')->get() as $tier) {
                if ($tier->knockout['confirmed_at'] ?? null) {
                    $broadcast = $progression->broadcast($tier, $courts->pluck('name', 'id')->all());
                    $broadcast['tournament_name'] = $tournaments->firstWhere('id', $tier->tournament_id)?->name;
                    $knockouts[] = $broadcast;

                    continue;
                }
                if ($tier->format === 'knockout') {
                    continue;
                }
                $overview = $progression->overview($tier);
                foreach ($overview['groups'] as $group) {
                    $standings[] = ['tier_id' => $tier->id, 'tier_name' => $tier->name, 'tournament_name' => $tournaments->firstWhere('id', $tier->tournament_id)?->name, 'group_name' => $group['name'], 'qualifiers' => $overview['qualifiers'], 'confirmed' => $overview['confirmed_at'] !== null, 'complete' => $overview['complete'], 'unresolved' => $group['unresolved'], 'rows' => $group['rows']];
                }
            }
        }

        return response()->json(['active_venue_id' => $venueId, 'venues' => $venues, 'courts' => $courts, 'tournaments' => $tournaments, 'standings' => $standings, 'knockouts' => $knockouts, 'server_time' => now()->toIso8601String()]);
    }

    private function admin(Request $request): void
    {
        abort_unless($request->user()->role === 'admin', 403, 'Hanya panitia yang dapat melakukan tindakan ini.');
    }

    private function validateRules(Request $request): array
    {
        return $request->validate([
            'rules.regular_sets' => ['required', Rule::in([1, 2])],
            'rules.games_to_win' => 'required|integer|min:2|max:9',
            'rules.golden_point' => 'required|boolean',
            'rules.deuce_rule' => ['sometimes', Rule::in(['advantage', 'golden', 'one_advantage'])],
            'rules.tiebreak_at' => 'sometimes|nullable|integer|min:2|lte:rules.games_to_win',
            'rules.tiebreak_win_by_two' => 'sometimes|boolean',
            'rules.tiebreak_points' => 'required|integer|min:5|max:21',
            'rules.super_points' => 'required|integer|min:7|max:21',
            'rules.super_win_by_two' => 'sometimes|boolean',
        ])['rules'];
    }

    public function preset(Request $request): JsonResponse
    {
        $this->admin($request);
        $data = $request->validate(['name' => 'required|string|max:100']);
        $rules = $this->validateRules($request);

        return response()->json(ScoringPreset::create([...$data, 'rules' => $rules]), 201);
    }

    public function deletePreset(Request $request, ScoringPreset $preset): JsonResponse
    {
        $this->admin($request);
        $preset->delete();

        return response()->json(['message' => 'Preset berhasil dihapus.']);
    }

    public function createVenue(Request $request): JsonResponse
    {
        $this->admin($request);
        $data = $request->validate(['name' => 'required|string|max:150']);
        $name = preg_replace('/\s+/u', ' ', trim($data['name']));
        if ($name === '' || Venue::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            throw ValidationException::withMessages(['name' => 'Nama venue wajib diisi dan belum digunakan.']);
        }

        return response()->json(Venue::create(['name' => $name]), 201);
    }

    public function updateVenue(Request $request, Venue $venue): JsonResponse
    {
        $this->admin($request);
        $data = $request->validate(['is_active' => 'required|boolean']);
        $venue->update($data);

        return response()->json($venue);
    }

    public function tournament(Request $request): JsonResponse
    {
        $this->admin($request);
        $data = $request->validate(['name' => 'required|string|max:100', 'venue_id' => 'required|integer|exists:venues,id', 'date' => 'nullable|date']);
        $data['venue'] = Venue::findOrFail($data['venue_id'])->name;

        return response()->json(Tournament::create($data), 201);
    }

    private function tierRevision(Tier $tier): string
    {
        return hash('sha256', json_encode([$tier->id, $tier->name, $tier->tournament_id, $tier->format, $tier->groups, $tier->rules, $tier->knockout]));
    }

    private function validateTier(Request $request): array
    {
        $data = $request->validate([
            'tournament_id' => 'required|exists:tournaments,id', 'name' => 'required|string|max:100',
            'format' => 'required|in:group,direct,knockout', 'draw_mode' => 'sometimes|in:ordered,random', 'groups' => 'required|array|min:1|max:16',
            'groups.*.name' => 'required|string|max:50|distinct',
            'groups.*.teams' => 'required|array|min:2|max:16',
            'groups.*.teams.*' => 'required|string|max:100',
        ]);
        $rules = $this->validateRules($request);
        $names = collect($data['groups'])->flatMap(fn (array $group) => $group['teams']);
        if ($names->unique()->count() !== $names->count()) {
            throw ValidationException::withMessages(['groups' => 'Nama pasangan harus unik dalam satu tier.']);
        }
        if ($data['format'] === 'direct' && collect($data['groups'])->contains(fn (array $group) => count($group['teams']) !== 2)) {
            throw ValidationException::withMessages(['groups' => 'Pertandingan langsung membutuhkan tepat dua pasangan per pertandingan.']);
        }
        if ($data['format'] === 'knockout') {
            abort_unless(count($data['groups']) === 1, 422, 'Knockout langsung membutuhkan satu daftar berisi 2 sampai 16 pasangan.');
        }

        return [...$data, 'rules' => $rules];
    }

    private function createTierMatches(Tier $tier, ScoringEngine $engine): void
    {
        if ($tier->format === 'knockout') {
            return;
        }
        foreach ($tier->groups as $group) {
            $teams = $group['teams'];
            for ($a = 0; $a < count($teams); $a++) {
                for ($b = $a + 1; $b < count($teams); $b++) {
                    PadelMatch::create(['tier_id' => $tier->id, 'group_name' => $group['name'], 'team_a' => $teams[$a], 'team_b' => $teams[$b], 'rules' => $tier->rules, 'state' => $engine->initial()]);
                }
            }
        }
    }

    public function tier(Request $request, ScoringEngine $engine): JsonResponse
    {
        $this->admin($request);
        $data = $this->validateTier($request);
        $drawMode = $data['draw_mode'] ?? 'ordered';
        unset($data['draw_mode']);
        if ($data['format'] === 'knockout' && $drawMode === 'random') {
            $data['groups'][0]['teams'] = collect($data['groups'][0]['teams'])->shuffle()->all();
        }
        $tier = DB::transaction(function () use ($data, $engine, $drawMode): Tier {
            $tier = Tier::create($data);
            if ($tier->format === 'knockout') {
                $tier->update(['knockout' => ['draw_mode' => $drawMode]]);
            }
            $this->createTierMatches($tier, $engine);

            return $tier;
        });

        return response()->json($tier, 201);
    }

    public function updateTier(Request $request, Tier $tier, ScoringEngine $engine): JsonResponse
    {
        $this->admin($request);
        $base = $request->validate(['name' => 'required|string|max:100', 'edit_revision' => 'required|string|size:64']);
        $fullEdit = $request->hasAny(['tournament_id', 'format', 'rules', 'groups', 'draw_mode']);
        $data = $fullEdit ? $this->validateTier($request) : ['name' => $base['name']];
        DB::transaction(function () use ($tier, $engine, $base, $fullEdit, $data): void {
            $locked = Tier::whereKey($tier->id)->lockForUpdate()->firstOrFail();
            abort_unless($base['edit_revision'] === $this->tierRevision($locked), 409, 'Tier sudah berubah. Tutup form dan buka kembali untuk memuat data terbaru.');
            if (! $fullEdit) {
                $locked->update($data);

                return;
            }
            $matches = PadelMatch::where('tier_id', $locked->id)->lockForUpdate()->get();
            abort_if(($locked->knockout['confirmed_at'] ?? null) || $matches->contains(fn (PadelMatch $match): bool => $match->status !== 'scheduled' || $match->started_at !== null) || DB::table('score_events')->whereIn('padel_match_id', $matches->pluck('id'))->exists(), 409, 'Pertandingan sudah dimulai atau bagan sudah dikonfirmasi. Hanya nama tier yang dapat diubah.');
            $drawMode = $data['draw_mode'] ?? ($locked->knockout['draw_mode'] ?? 'ordered');
            $structuralChange = (int) $data['tournament_id'] !== $locked->tournament_id || $data['format'] !== $locked->format || $data['groups'] != $locked->groups || ($data['format'] === 'knockout' && $drawMode !== ($locked->knockout['draw_mode'] ?? 'ordered'));
            $rulesChanged = $data['rules'] != $locked->rules;
            unset($data['draw_mode']);
            if ($structuralChange && $data['format'] === 'knockout' && $drawMode === 'random') {
                $data['groups'][0]['teams'] = collect($data['groups'][0]['teams'])->shuffle()->all();
            }
            $locked->fill($data);
            if ($structuralChange) {
                $locked->knockout = $data['format'] === 'knockout' ? ['draw_mode' => $drawMode] : null;
            }
            $locked->save();
            if ($structuralChange) {
                CourtReservation::where('tier_id', $locked->id)->delete();
                PadelMatch::where('tier_id', $locked->id)->delete();
                $this->createTierMatches($locked, $engine);
            } elseif ($rulesChanged) {
                foreach ($matches as $match) {
                    $match->rules = $locked->rules;
                    $match->version++;
                    $match->save();
                }
            }
        }, 3);

        return response()->json($tier->fresh());
    }

    public function progression(Request $request, Tier $tier, TournamentProgression $progression): JsonResponse
    {
        $this->admin($request);
        abort_unless(in_array($tier->format, ['group', 'knockout'], true), 422, 'Tier ini tidak menggunakan bagan knockout.');

        return response()->json($progression->overview($tier));
    }

    public function configureProgression(Request $request, Tier $tier): JsonResponse
    {
        $this->admin($request);
        $data = $request->validate(['qualifiers' => 'required|integer|in:1,2', 'tie_orders' => 'sometimes|array', 'tie_orders.*' => 'array', 'tie_orders.*.*' => 'string']);
        DB::transaction(function () use ($tier, $data): void {
            $locked = Tier::whereKey($tier->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->format === 'group', 422);
            abort_if($locked->knockout['confirmed_at'] ?? null, 409, 'Bagan sudah dikonfirmasi dan terkunci.');
            abort_if(count($locked->groups) * $data['qualifiers'] < 2, 422, 'Knockout membutuhkan minimal dua peserta lolos.');
            $orders = $data['tie_orders'] ?? [];
            if ($orders) {
                abort_if(PadelMatch::where('tier_id', $locked->id)->where('stage', 'group')->where('status', '!=', 'finished')->exists(), 409, 'Urutan penentu ditetapkan setelah seluruh pertandingan grup selesai.');
            }
            foreach ($orders as $name => $order) {
                $group = collect($locked->groups)->firstWhere('name', $name);
                abort_unless($group && count($order) === count($group['teams']) && count(array_unique($order)) === count($order) && ! array_diff($order, $group['teams']), 422, 'Urutan penentu harus memuat seluruh pasangan grup tepat satu kali.');
            }
            if (($locked->knockout['qualifiers'] ?? 2) !== (int) $data['qualifiers']) {
                CourtReservation::where('tier_id', $locked->id)->delete();
            }
            $schedule = ($locked->knockout['qualifiers'] ?? 2) === (int) $data['qualifiers'] ? ($locked->knockout['schedule'] ?? []) : [];
            $locked->update(['knockout' => ['qualifiers' => $data['qualifiers'], 'tie_orders' => $orders, 'schedule' => $schedule]]);
        });

        return response()->json(['message' => 'Pengaturan kelolosan disimpan.']);
    }

    public function scheduleKnockout(Request $request, Tier $tier, TournamentProgression $progression): JsonResponse
    {
        $this->admin($request);
        $data = $request->validate(['round' => 'required|integer|min:0', 'position' => 'required|integer|min:0', 'court_id' => 'present|nullable|exists:courts,id', 'revision' => 'required|string|size:64']);
        DB::transaction(function () use ($tier, $data, $progression): void {
            $locked = Tier::whereKey($tier->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($locked->format, ['group', 'knockout'], true), 422);
            abort_unless($data['revision'] === $progression->scheduleRevision($locked), 409, 'Format knockout berubah. Periksa jadwal terbaru.');
            $fixture = collect($progression->scheduledFixtures($locked))->first(fn (array $fixture): bool => $fixture['round'] === (int) $data['round'] && $fixture['position'] === (int) $data['position']);
            abort_unless($fixture && ! $fixture['bye'], 409, 'Pertandingan sudah tersedia atau slot ini merupakan BYE. Periksa daftar terbaru.');
            if ($data['court_id']) {
                $court = Court::whereKey($data['court_id'])->lockForUpdate()->firstOrFail();
                abort_unless($court->venue_id === Tournament::findOrFail($locked->tournament_id)->venue_id, 422, 'Pilih court yang berada di venue turnamen ini.');
            }
            $reservation = CourtReservation::where('tier_id', $locked->id)->where('round', $data['round'])->where('position', $data['position'])->first();
            if ($data['court_id']) {
                if (! $reservation || $reservation->court_id !== (int) $data['court_id']) {
                    CourtReservation::updateOrCreate(['tier_id' => $locked->id, 'round' => $data['round'], 'position' => $data['position']], ['court_id' => (int) $data['court_id'], 'queue_position' => (new CourtQueue)->nextPosition((int) $data['court_id']), 'version' => ($reservation?->version ?? -1) + 1]);
                }
            } else {
                $reservation?->delete();
            }
            $config = $locked->knockout ?? [];
            $config['schedule'][$data['round'].':'.$data['position']] = $data['court_id'] ? (int) $data['court_id'] : null;
            $locked->update(['knockout' => $config]);
        }, 3);

        return response()->json(['message' => 'Court knockout disimpan.']);
    }

    public function confirmKnockout(Request $request, Tier $tier, TournamentProgression $progression): JsonResponse
    {
        $this->admin($request);
        $requestRevision = $request->validate(['revision' => 'required|string|size:64'])['revision'];
        DB::transaction(function () use ($tier, $progression, $requestRevision): void {
            $locked = Tier::whereKey($tier->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($locked->format, ['group', 'knockout'], true), 422);
            abort_if($locked->knockout['confirmed_at'] ?? null, 409, 'Bagan sudah dibuat.');
            $overview = $progression->overview($locked);
            abort_unless($requestRevision === $overview['revision'], 409, 'Klasemen atau aturan berubah. Periksa preview terbaru sebelum mengonfirmasi.');
            abort_unless($overview['ready'], 409, 'Selesaikan seluruh pertandingan grup dan tetapkan urutan peringkat yang masih seri.');
            abort_if($locked->format === 'group' && count($locked->groups) * $overview['qualifiers'] < 2, 422, 'Minimal dua peserta harus lolos.');
            $locked->update(['knockout' => [...($locked->knockout ?? []), 'qualifiers' => $overview['qualifiers'], 'rounds' => $overview['rounds'], 'confirmed_at' => now()->toIso8601String()]]);
            $progression->advance($locked);
        }, 3);

        return response()->json($progression->overview($tier->fresh()));
    }

    public function referee(Request $request): JsonResponse
    {
        $this->admin($request);
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:150|unique:users', 'password' => 'required|string|min:10']);
        $data['email'] = strtolower($data['email']);

        return response()->json(User::create($data), 201);
    }

    public function updateReferee(Request $request, User $referee): JsonResponse
    {
        $this->admin($request);
        abort_unless($referee->role === 'referee', 404, 'Akun wasit tidak ditemukan.');
        $data = $request->validate(['name' => 'required|string|max:100']);
        $name = preg_replace('/\s+/u', ' ', trim($data['name']));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Nama wasit wajib diisi.']);
        }
        $referee->update(['name' => $name]);

        return response()->json($referee);
    }

    public function createCourt(Request $request): JsonResponse
    {
        $this->admin($request);
        $data = $request->validate(['name' => 'required|string|max:100', 'venue_id' => 'required|integer|exists:venues,id']);
        $name = preg_replace('/\s+/u', ' ', trim($data['name']));
        if ($name === '' || Court::where('venue_id', $data['venue_id'])->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            throw ValidationException::withMessages(['name' => 'Nama court wajib diisi dan belum digunakan.']);
        }

        return response()->json(Court::create(['name' => $name, 'venue_id' => $data['venue_id']]), 201);
    }

    public function deleteCourt(Request $request, Court $court): JsonResponse
    {
        $this->admin($request);
        DB::transaction(function () use ($court): void {
            $locked = Court::whereKey($court->id)->lockForUpdate()->firstOrFail();
            $matches = PadelMatch::where('court_id', $locked->id)->lockForUpdate()->get();
            abort_if($matches->contains(fn (PadelMatch $match): bool => $match->status !== 'finished'), 409, 'Court masih memiliki pertandingan yang belum selesai. Selesaikan pertandingan atau pindahkan antreannya terlebih dahulu.');
            PadelMatch::where('court_id', $locked->id)->update(['court_id' => null, 'queue_position' => null, 'version' => DB::raw('version + 1')]);
            $locked->delete();
        }, 3);

        return response()->json(['message' => 'Court berhasil dihapus.']);
    }

    public function court(Request $request, Court $court): JsonResponse
    {
        $this->admin($request);
        $data = $request->validate(['name' => 'sometimes|required|string|max:100', 'venue_id' => 'sometimes|required|integer|exists:venues,id', 'referee_id' => ['sometimes', 'nullable', Rule::exists('users', 'id')->where('role', 'referee'), Rule::unique('courts')->ignore($court->id)]]);
        DB::transaction(function () use ($court, $data): void {
            $locked = Court::whereKey($court->id)->lockForUpdate()->firstOrFail();
            $venueId = isset($data['venue_id']) ? (int) $data['venue_id'] : $locked->venue_id;
            abort_if($venueId !== $locked->venue_id && (PadelMatch::where('court_id', $locked->id)->where('status', '!=', 'finished')->exists() || CourtReservation::where('court_id', $locked->id)->exists()), 409, 'Pindahkan atau selesaikan seluruh pertandingan sebelum memindahkan court ke venue lain.');
            $name = preg_replace('/\s+/u', ' ', trim($data['name'] ?? $locked->name));
            if ($name === '' || Court::where('venue_id', $venueId)->where('id', '!=', $locked->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
                throw ValidationException::withMessages(['name' => 'Nama court sudah digunakan di venue ini.']);
            }
            $locked->update([...$data, 'name' => $name]);
        }, 3);

        return response()->json($court->fresh());
    }

    public function assign(Request $request, PadelMatch $match): JsonResponse
    {
        $this->admin($request);
        $data = $request->validate(['court_id' => 'present|nullable|exists:courts,id']);
        DB::transaction(function () use ($data, $match): void {
            $court = $data['court_id'] ? Court::whereKey($data['court_id'])->lockForUpdate()->firstOrFail() : null;
            $locked = PadelMatch::whereKey($match->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'scheduled', 409, 'Pertandingan sudah dimulai.');
            if (! $court) {
                $locked->court_id = null;
                $locked->queue_position = null;
                $locked->version++;
                $locked->save();

                return;
            }
            $tournament = Tournament::findOrFail(Tier::findOrFail($locked->tier_id)->tournament_id);
            abort_unless($court->venue_id === $tournament->venue_id, 422, 'Pilih court yang berada di venue turnamen ini.');
            if ($locked->court_id === $court->id) {
                return;
            }
            $locked->court_id = $court->id;
            $locked->queue_position = (new CourtQueue)->nextPosition($court->id);
            $locked->version++;
            $locked->save();
        }, 3);

        return response()->json($match->fresh());
    }

    public function reorder(Request $request, PadelMatch $match): JsonResponse
    {
        $this->admin($request);
        $data = $request->validate(['direction' => 'required|in:up,down', 'version' => 'required|integer|min:0']);
        DB::transaction(function () use ($match, $data): void {
            $locked = PadelMatch::whereKey($match->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'scheduled' && $locked->court_id, 409, 'Hanya antrean yang dapat diurutkan.');
            Court::whereKey($locked->court_id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $data['version'], 409, 'Antrean berubah. Periksa urutan terbaru.');
            (new CourtQueue)->move($locked, $data['direction']);
        }, 3);

        return response()->json($match->fresh());
    }

    public function reorderReservation(Request $request, CourtReservation $reservation): JsonResponse
    {
        $this->admin($request);
        $data = $request->validate(['direction' => 'required|in:up,down', 'version' => 'required|integer|min:0']);
        DB::transaction(function () use ($reservation, $data): void {
            Court::whereKey($reservation->court_id)->lockForUpdate()->firstOrFail();
            $locked = CourtReservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $data['version'], 409, 'Antrean berubah. Periksa urutan terbaru.');
            (new CourtQueue)->move($locked, $data['direction']);
        }, 3);

        return response()->json($reservation->fresh());
    }

    public function history(Request $request, PadelMatch $match): JsonResponse
    {
        $this->authorizeMatch($request, $match);

        return response()->json(DB::table('score_events')->where('padel_match_id', $match->id)->orderByDesc('id')->limit(40)->get(['id', 'action', 'team', 'user_id', 'undone', 'created_at']));
    }

    private function authorizeMatch(Request $request, PadelMatch $match): void
    {
        abort_unless($request->user()->role === 'admin' || ($match->court_id && Court::whereKey($match->court_id)->where('referee_id', $request->user()->id)->exists()), 403, 'Pertandingan bukan penugasan Anda.');
    }

    public function score(Request $request, PadelMatch $match, ScoringEngine $engine): JsonResponse
    {
        $data = $request->validate([
            'action' => 'required|in:start,point,undo,pause,resume,confirm,reopen,reset',
            'team' => 'required_if:action,point|integer|in:0,1',
            'server' => 'required_if:action,start|integer|between:0,3',
            'version' => 'required|integer|min:0', 'command_id' => 'required|uuid',
        ]);
        $result = DB::transaction(function () use ($request, $match, $engine, $data): PadelMatch {
            $tier = Tier::whereKey($match->tier_id)->lockForUpdate()->firstOrFail();
            $locked = PadelMatch::whereKey($match->id)->lockForUpdate()->firstOrFail();
            $this->authorizeMatch($request, $locked);
            $existing = DB::table('score_events')->where('command_id', $data['command_id'])->first();
            if ($existing) {
                abort_unless($existing->padel_match_id === $locked->id && $existing->user_id === $request->user()->id, 409);

                return $locked;
            }
            abort_unless($locked->version === $data['version'], 409, 'Skor telah berubah. Muat ulang sebelum melanjutkan.');
            $state = $locked->state;
            $action = $data['action'];
            if ($action === 'start') {
                abort_unless($locked->status === 'scheduled' && $locked->court_id, 409, 'Pertandingan belum siap dimulai.');
                Court::whereKey($locked->court_id)->lockForUpdate()->firstOrFail();
                abort_if(PadelMatch::where('court_id', $locked->court_id)->whereIn('status', ['live', 'paused', 'review'])->exists(), 409, 'Court masih menjalankan pertandingan sebelumnya.');
                $head = (new CourtQueue)->pending($locked->court_id)->first();
                abort_unless($head instanceof PadelMatch && $head->id === $locked->id, 409, 'Mulai pertandingan sesuai urutan antrean.');
                abort_if(PadelMatch::where('tier_id', $locked->tier_id)->where('id', '!=', $locked->id)->whereIn('status', ['live', 'paused', 'review'])->where(function ($query) use ($locked): void {
                    $query->whereIn('team_a', [$locked->team_a, $locked->team_b])->orWhereIn('team_b', [$locked->team_a, $locked->team_b]);
                })->exists(), 409, 'Pasangan masih bermain di court lain.');
                $locked->state = $engine->initial($data['server']);
                $locked->status = 'live';
                $locked->started_at = now();
            } elseif ($action === 'reset') {
                abort_unless(in_array($locked->status, ['live', 'paused', 'review'], true), 409, 'Hanya pertandingan yang sedang berjalan atau belum dikonfirmasi yang dapat dikembalikan ke jadwal.');
                abort_if($locked->stage === 'group' && ($tier->knockout['confirmed_at'] ?? null), 409, 'Hasil grup sudah menjadi dasar bagan knockout yang dikonfirmasi.');
                $locked->state = $engine->initial();
                $locked->status = 'scheduled';
                $locked->started_at = null;
                $locked->finished_at = null;
                DB::table('score_events')->where('padel_match_id', $locked->id)->where('action', 'point')->where('undone', false)->update(['undone' => true, 'updated_at' => now()]);
            } elseif ($action === 'point') {
                abort_unless($locked->status === 'live', 409, 'Pertandingan tidak sedang berlangsung.');
                $locked->state = $engine->point($state, $data['team'], $locked->rules);
                if ($locked->state['winner'] !== null) {
                    $locked->status = 'review';
                }
            } elseif ($action === 'undo') {
                abort_unless(in_array($locked->status, ['live', 'paused', 'review']), 409, 'Skor tidak dapat dibatalkan.');
                $event = DB::table('score_events')->where('padel_match_id', $locked->id)->where('action', 'point')->where('undone', false)->latest('id')->first();
                abort_unless($event, 409, 'Belum ada poin untuk dibatalkan.');
                $locked->state = json_decode($event->before_state, true);
                $locked->status = $locked->status === 'paused' ? 'paused' : 'live';
                DB::table('score_events')->where('id', $event->id)->update(['undone' => true]);
            } elseif ($action === 'pause' || $action === 'resume') {
                abort_unless($locked->status === ($action === 'pause' ? 'live' : 'paused'), 409, 'Status pertandingan sudah berubah.');
                $locked->status = $action === 'pause' ? 'paused' : 'live';
            } elseif ($action === 'confirm') {
                abort_unless($locked->status === 'review', 409, 'Pertandingan belum selesai.');
                $locked->status = 'finished';
                $locked->finished_at = now();
            } else {
                $this->admin($request);
                abort_if($tier->knockout['confirmed_at'] ?? null, 409, 'Hasil terkunci karena menjadi dasar bagan knockout yang sudah dikonfirmasi.');
                abort_unless($locked->status === 'finished', 409);
                abort_if(PadelMatch::where('court_id', $locked->court_id)->where('id', '!=', $locked->id)->whereIn('status', ['live', 'paused', 'review'])->exists(), 409, 'Court sudah digunakan pertandingan berikutnya.');
                $locked->status = 'review';
                $locked->finished_at = null;
                if ($tier->knockout) {
                    $tier->update(['knockout' => ['qualifiers' => $tier->knockout['qualifiers'] ?? 2, 'schedule' => $tier->knockout['schedule'] ?? []]]);
                }
            }
            $locked->version++;
            $locked->save();
            DB::table('score_events')->insert(['padel_match_id' => $locked->id, 'user_id' => $request->user()->id, 'command_id' => $data['command_id'], 'action' => $action, 'team' => $data['team'] ?? null, 'before_state' => json_encode($state), 'undone' => false, 'created_at' => now(), 'updated_at' => now()]);

            if ($action === 'confirm') {
                (new TournamentProgression)->advance($tier);
            }

            return $locked;
        }, 3);

        return response()->json($result);
    }
}
