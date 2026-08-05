<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One ordering row per received event for FIFO proxies only (ADR-011 Decision 2,
     * AC6/AC7). Holds claim state only — no payload/outcome column, no soft delete.
     * `webhook_event_id` is UNIQUE (the monotonic order key and the capture-idempotency
     * guard). The `(proxy_id, status, webhook_event_id)` composite serves both the
     * lowest-pending scan and the live-claim check the advancer runs. FKs use the
     * default restrict `constrained()`; `team_id`/`proxy_id` are set explicitly on the
     * team-unscoped ingest path (mirrors webhook_events/delivery_attempts).
     */
    public function up(): void
    {
        Schema::create('fifo_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained();
            $table->foreignId('proxy_id')->constrained();
            $table->foreignId('webhook_event_id')->constrained();
            $table->enum('status', ['pending', 'claimed', 'settled'])->default('pending');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique('webhook_event_id');
            $table->index(['proxy_id', 'status', 'webhook_event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fifo_dispatches');
    }
};
