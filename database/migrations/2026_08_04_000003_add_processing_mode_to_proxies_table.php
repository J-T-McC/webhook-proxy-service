<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-proxy processing mode (ADR-011 Decision 1, AC4/AC5). Mirrors the `mode`
     * enum exactly: NOT NULL, schema default `'async'`. No backfill — existing #1/#3
     * rows read `async` and keep their current fan-out behaviour with no surprise.
     */
    public function up(): void
    {
        Schema::table('proxies', function (Blueprint $table) {
            $table->enum('processing_mode', ['async', 'fifo'])->default('async')->after('mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('proxies', function (Blueprint $table) {
            $table->dropColumn('processing_mode');
        });
    }
};
