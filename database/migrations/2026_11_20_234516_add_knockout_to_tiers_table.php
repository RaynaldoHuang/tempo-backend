<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiers', fn (Blueprint $table) => $table->json('knockout')->nullable());
        Schema::table('padel_matches', fn (Blueprint $table) => $table->string('stage')->default('group'));
    }

    public function down(): void
    {
        Schema::table('tiers', fn (Blueprint $table) => $table->dropColumn('knockout'));
        Schema::table('padel_matches', fn (Blueprint $table) => $table->dropColumn('stage'));
    }
};
