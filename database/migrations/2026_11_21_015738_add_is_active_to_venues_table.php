<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', fn (Blueprint $table) => $table->boolean('is_active')->default(false));
        $venueId = DB::table('tournaments')->whereNotNull('venue_id')->orderByDesc('id')->value('venue_id')
            ?? DB::table('courts')->whereNotNull('venue_id')->orderBy('id')->value('venue_id')
            ?? DB::table('venues')->orderBy('id')->value('id');
        if ($venueId) {
            DB::table('venues')->where('id', $venueId)->update(['is_active' => true]);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE venues DROP COLUMN is_active');
        } else {
            Schema::table('venues', fn (Blueprint $table) => $table->dropColumn('is_active'));
        }
    }
};
