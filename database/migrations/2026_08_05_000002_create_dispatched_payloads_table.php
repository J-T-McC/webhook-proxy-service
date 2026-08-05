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
     * The dispatched-output store (AC12, AC13, AC15; ADR-013 Decision 1) — one
     * row per received event holding the payload actually sent downstream,
     * captured only when it diverges from the raw capture (ADR-013 Decision 2).
     * `webhook_event_id` is UNIQUE (one row per event, and orphan prevention for
     * a future delete path only — not an AC6/AC12 mechanism at #5) and
     * `cascadeOnDelete()`, unlike the restrict-by-default FKs elsewhere, since
     * this table has no independent lifecycle of its own. No `headers`, no
     * `method`, no retention/GC column of its own, no soft delete, no backfill —
     * existing #3/#4 events simply have no output row.
     */
    public function up(): void
    {
        Schema::create('dispatched_payloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained();
            $table->foreignId('proxy_id')->constrained();
            $table->foreignId('webhook_event_id')->constrained()->cascadeOnDelete();
            // `body` is added below as LONGBLOB — Blueprint's binary() maps to MySQL
            // BLOB (64 KiB), too small for the encrypted envelope (same treatment as
            // webhook_events.body).
            $table->unsignedInteger('byte_size');
            $table->timestamp('dispatched_at');
            $table->timestamps();

            $table->unique('webhook_event_id');
            $table->index(['team_id', 'created_at']);
        });

        DB::statement('ALTER TABLE `dispatched_payloads` ADD `body` LONGBLOB NULL AFTER `webhook_event_id`');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dispatched_payloads');
    }
};
