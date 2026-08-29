<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Item #15 (pause and resume dispatch, AC1). Nullable timestamp, not a
     * boolean: null means never paused/currently resumed, a value is both the
     * two-state signal and the "says when it was paused" surface (AC14).
     * Every existing proxy is unpaused by construction (AC7) — no backfill.
     */
    public function up(): void
    {
        Schema::table('proxies', function (Blueprint $table) {
            $table->timestamp('paused_at')->nullable()->after('processing_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('proxies', function (Blueprint $table) {
            $table->dropColumn('paused_at');
        });
    }
};
