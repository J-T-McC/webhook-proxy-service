<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Gives `fifo_dispatches` a dispatch identity independent of
     * `webhook_event_id` and a held-for-retry status (AC6, AC11; ADR-016
     * Decision 3 — carries the ADR-011 P1/P2 supersession, ✋ flag 7).
     * Ordering is load-bearing (plan Implementation Notes) and must never be
     * reversed or collapsed — each step is its own statement:
     *
     * (1) add `dispatch_uuid` nullable — a NOT NULL column cannot be added to a
     *     table with existing rows without first giving every row a value;
     * (2) backfill every existing row's `dispatch_uuid` from its event's
     *     `ingest_id` — an in-place, mechanical identity backfill (dev/CI data
     *     only, no production data exists);
     * (3) harden `dispatch_uuid` to NOT NULL, now that every row has a value;
     * (4) add `UNIQUE(dispatch_uuid)` — the new dispatch-identity guard;
     * (5) add a **plain** index on `webhook_event_id` — a FK requires a
     *     supporting index on MySQL, and this must exist *before* (6) or the
     *     drop fails (MySQL error 1553, the same class of failure T5 hit in
     *     reverse — see `delivery_attempts.delivery_id`);
     * (6) drop `UNIQUE(webhook_event_id)` — a dispatch may now compose more
     *     than one ordering row per event (retry/replay), so the old
     *     one-row-per-event guard must go;
     * (7) append `'awaiting_retry'` to the `status` enum — metadata-only on
     *     MySQL 8.0 because it is appended, never inserted mid-list or used to
     *     reorder/remove existing values.
     */
    public function up(): void
    {
        Schema::table('fifo_dispatches', function (Blueprint $table) {
            $table->uuid('dispatch_uuid')->nullable()->after('webhook_event_id');
        });

        DB::statement(
            'UPDATE `fifo_dispatches`
             INNER JOIN `webhook_events` ON `webhook_events`.`id` = `fifo_dispatches`.`webhook_event_id`
             SET `fifo_dispatches`.`dispatch_uuid` = `webhook_events`.`ingest_id`',
        );

        DB::statement('ALTER TABLE `fifo_dispatches` MODIFY `dispatch_uuid` CHAR(36) NOT NULL');

        Schema::table('fifo_dispatches', function (Blueprint $table) {
            $table->unique('dispatch_uuid');
        });

        Schema::table('fifo_dispatches', function (Blueprint $table) {
            $table->index('webhook_event_id');
        });

        Schema::table('fifo_dispatches', function (Blueprint $table) {
            $table->dropUnique(['webhook_event_id']);
        });

        DB::statement(
            "ALTER TABLE `fifo_dispatches` MODIFY `status` ENUM('pending', 'claimed', 'settled', 'awaiting_retry') NOT NULL DEFAULT 'pending'",
        );
    }

    /**
     * Reverse the migrations.
     *
     * Mirrors `up()` in strict reverse order. Restoring `UNIQUE(webhook_event_id)`
     * *before* dropping the plain index added in `up()` step (5) keeps the FK
     * continuously supported by an index throughout — the same precaution T5's
     * `down()` needed, applied here to the add side instead of the drop side.
     * Dropping `'awaiting_retry'` from the enum is BEST-EFFORT ONLY — it fails if
     * any row currently holds that value, mirroring the #5 payload-erasure
     * migration's documented non-round-tripping caveat. No production data exists
     * for this feature, so this is acceptable.
     */
    public function down(): void
    {
        DB::statement(
            "ALTER TABLE `fifo_dispatches` MODIFY `status` ENUM('pending', 'claimed', 'settled') NOT NULL DEFAULT 'pending'",
        );

        Schema::table('fifo_dispatches', function (Blueprint $table) {
            $table->unique('webhook_event_id');
        });

        Schema::table('fifo_dispatches', function (Blueprint $table) {
            $table->dropIndex(['webhook_event_id']);
        });

        Schema::table('fifo_dispatches', function (Blueprint $table) {
            $table->dropUnique(['dispatch_uuid']);
        });

        Schema::table('fifo_dispatches', function (Blueprint $table) {
            $table->dropColumn('dispatch_uuid');
        });
    }
};
