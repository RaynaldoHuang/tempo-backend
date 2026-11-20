<?php

use App\Http\Controllers\PadelController;
use App\Http\Middleware\PadelAuth;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok']));
Route::get('/v1/board', [PadelController::class, 'board']);
Route::post('/v1/login', [PadelController::class, 'login'])->middleware('throttle:10,1');
Route::prefix('v1')->middleware(PadelAuth::class)->group(function (): void {
    Route::get('/data', [PadelController::class, 'data']);
    Route::post('/logout', [PadelController::class, 'logout']);
    Route::post('/password', [PadelController::class, 'password']);
    Route::post('/presets', [PadelController::class, 'preset']);
    Route::delete('/presets/{preset}', [PadelController::class, 'deletePreset']);
    Route::post('/tournaments', [PadelController::class, 'tournament']);
    Route::get('/tiers/{tier}/progression', [PadelController::class, 'progression']);
    Route::post('/tiers/{tier}/progression', [PadelController::class, 'configureProgression']);
    Route::post('/tiers/{tier}/knockout', [PadelController::class, 'confirmKnockout']);
    Route::post('/tiers', [PadelController::class, 'tier']);
    Route::post('/referees', [PadelController::class, 'referee']);
    Route::patch('/courts/{court}', [PadelController::class, 'court']);
    Route::post('/matches/{match}/assign', [PadelController::class, 'assign']);
    Route::post('/matches/{match}/reorder', [PadelController::class, 'reorder']);
    Route::post('/matches/{match}/score', [PadelController::class, 'score']);
    Route::get('/matches/{match}/history', [PadelController::class, 'history']);
});
