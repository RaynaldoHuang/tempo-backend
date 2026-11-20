<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('referee');
            $table->string('api_token', 64)->nullable()->unique();
            $table->timestamp('token_expires_at')->nullable();
        });
        Schema::create('scoring_presets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('rules');
            $table->timestamps();
        });
        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('venue')->nullable();
            $table->date('date')->nullable();
            $table->timestamps();
        });
        Schema::create('tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('format');
            $table->json('rules');
            $table->json('groups');
            $table->timestamps();
        });
        Schema::create('courts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('referee_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('padel_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('court_id')->nullable()->constrained()->nullOnDelete();
            $table->string('group_name');
            $table->string('team_a');
            $table->string('team_b');
            $table->string('status')->default('scheduled')->index();
            $table->json('rules');
            $table->json('state');
            $table->unsignedInteger('version')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::create('score_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('padel_match_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->uuid('command_id')->unique();
            $table->string('action');
            $table->unsignedTinyInteger('team')->nullable();
            $table->json('before_state');
            $table->boolean('undone')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_events');
        Schema::dropIfExists('padel_matches');
        Schema::dropIfExists('courts');
        Schema::dropIfExists('tiers');
        Schema::dropIfExists('tournaments');
        Schema::dropIfExists('scoring_presets');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'api_token', 'token_expires_at']));
    }
};
