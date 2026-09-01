<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * ADR-028: `deliveries.status` gains `skipped`, the terminal state for a
     * delivery whose destination was not validated at send time (#18 AC8's
     * dispatch-gate, AC11).
     *
     * No row is rewritten — nothing was skipped before this shipped. The
     * column keeps its `pending` default.
     *
     * Raw DDL rather than a Blueprint change: altering an `enum` in place
     * needs the full value list restated, and Doctrine DBAL (which the
     * `change()` helper would require) is not installed.
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE deliveries MODIFY COLUMN status
             ENUM('pending', 'retrying', 'succeeded', 'failed', 'skipped')
             NOT NULL DEFAULT 'pending'"
        );
    }

    /**
     * Reverse the migrations.
     *
     * Any row sitting at `skipped` is moved to `failed` first, because the
     * narrowed enum cannot hold it. That is lossy in meaning — a skip is not a
     * failure (AC11) — and is the honest cost of rolling this back rather than
     * a silent truncation to an empty string, which is what MySQL would
     * otherwise do.
     */
    public function down(): void
    {
        DB::table('deliveries')->where('status', 'skipped')->update(['status' => 'failed']);

        DB::statement(
            "ALTER TABLE deliveries MODIFY COLUMN status
             ENUM('pending', 'retrying', 'succeeded', 'failed')
             NOT NULL DEFAULT 'pending'"
        );
    }
};
