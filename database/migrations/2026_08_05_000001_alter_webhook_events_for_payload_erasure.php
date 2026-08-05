<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Puts a lifecycle on the #3 raw capture (AC6, AC11, AC21, AC22; ADR-014
     * Decisions 2-7). MySQL-specific, raw `ALTER`, following the #3 precedent —
     * no portable Blueprint equivalent exists for either column type touched here.
     *
     * (a) `body`: LONGBLOB NOT NULL -> LONGBLOB NULL (value-preserving `MODIFY`) —
     *     the erasure target.
     * (b) `headers`: DROPPED AND RE-ADDED (NOT a `MODIFY`) as MEDIUMTEXT NULL,
     *     `AFTER method` (preserving original column order), moving the cast to
     *     `'encrypted:array'` (AC22a). This is a mandatory, not cosmetic, type
     *     change: MySQL validates `json` on write and the `encrypted` envelope is
     *     not valid JSON (error 3140). Existing rows hold PLAINTEXT json the new
     *     cast cannot decrypt.
     *
     *     THIS STEP DISCARDS EVERY EXISTING CAPTURED HEADER VALUE in any
     *     local/CI database that already has `webhook_events` rows. This is
     *     intentional and Owner-approved (ADR-014 Decision 2 reasoning: "there is
     *     no production data to protect"), not an oversight.
     * (c) add `payload_cleaned_at TIMESTAMP NULL AFTER byte_size` — the AC21
     *     cleaned-state signal.
     * (d) add composite index `(team_id, payload_cleaned_at, created_at)` — turns
     *     the GC selection into a seek over uncleaned rows only.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE `webhook_events` MODIFY `body` LONGBLOB NULL');

        Schema::table('webhook_events', function ($table) {
            $table->dropColumn('headers');
        });
        DB::statement('ALTER TABLE `webhook_events` ADD `headers` MEDIUMTEXT NULL AFTER `method`');

        DB::statement('ALTER TABLE `webhook_events` ADD `payload_cleaned_at` TIMESTAMP NULL AFTER `byte_size`');

        Schema::table('webhook_events', function ($table) {
            $table->index(['team_id', 'payload_cleaned_at', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * BEST-EFFORT ONLY — DOES NOT ROUND-TRIP. Re-adds `headers` as `json NOT
     * NULL` (empty on every row that held header data — the plaintext is gone,
     * not recoverable) and restores `body NOT NULL`, which FAILS against any row
     * already erased (a NULL `body` cannot become NOT NULL). Acceptable on the
     * same no-production-data basis as `up()` — do not rely on this to restore
     * data.
     */
    public function down(): void
    {
        Schema::table('webhook_events', function ($table) {
            $table->dropIndex(['team_id', 'payload_cleaned_at', 'created_at']);
            $table->dropColumn('payload_cleaned_at');
            $table->dropColumn('headers');
        });

        DB::statement('ALTER TABLE `webhook_events` ADD `headers` JSON NOT NULL AFTER `method`');
        DB::statement('ALTER TABLE `webhook_events` MODIFY `body` LONGBLOB NOT NULL');
    }
};
