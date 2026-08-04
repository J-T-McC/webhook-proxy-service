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
     * Raw-only, immutable capture of each incoming webhook (ADR-010, AC5/AC7–AC9).
     * No dispatched/derived-output column, no retention/GC column, no soft delete —
     * raw-only and immutable by construction (retention/GC is #5). FKs use the
     * default restrict `constrained()` like `delivery_attempts`; `team_id`/`proxy_id`
     * are set explicitly on the team-unscoped ingest path.
     */
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained();
            $table->foreignId('proxy_id')->constrained();
            // The SAME correlator carried by delivery_attempts (ADR-003); UNIQUE also
            // makes capture idempotent under a future #4 at-least-once replay.
            $table->uuid('ingest_id')->unique();
            $table->string('method', 7);
            // Inbound headers as received (plaintext until #10, ADR-010 Amendment B).
            $table->json('headers');
            $table->string('content_type')->nullable();
            // `body` is added below as LONGBLOB — Blueprint's binary() maps to MySQL
            // BLOB (64 KiB), too small for the encrypted envelope at the ADR-006 body
            // cap. LONGBLOB (~4 GiB) is binary-safe and absorbs the ~35% cast overhead
            // (ADR-010 Amendment B). byte_size records the PLAINTEXT received size.
            $table->unsignedInteger('byte_size');
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['team_id', 'created_at']);
            $table->index(['proxy_id', 'created_at']);
        });

        // Raw column-type statement for the LONGBLOB body (no Blueprint helper exists).
        DB::statement('ALTER TABLE `webhook_events` ADD `body` LONGBLOB NOT NULL AFTER `content_type`');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
