<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The retry/replay unit of work (ADR-015 Decision 1) — one row per
     * (dispatch, destination) pair, tracking status through retry without
     * carrying any payload of its own (attempt rows stay payload-free,
     * ADR-003; retries resend the recorded dispatched output, never a copy
     * held here). `dispatch_uuid` identifies one logical dispatch (original
     * send or replay, ADR-017) across all of a proxy's destinations;
     * `UNIQUE(dispatch_uuid, destination_id)` is the one-row-per-pair
     * guarantee. `status` is transitioned only by compare-and-set on the
     * query builder, keyed on the prior status — never a blind `save()`
     * (plan-06 binding invariant). All FKs restrict (`constrained()`,
     * default), mirroring `delivery_attempts`/`fifo_dispatches`. No soft
     * delete, no payload column.
     */
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained();
            $table->foreignId('proxy_id')->constrained();
            $table->foreignId('destination_id')->constrained();
            $table->foreignId('webhook_event_id')->constrained();
            $table->uuid('dispatch_uuid');
            $table->enum('kind', ['original', 'replay']);
            $table->enum('status', ['pending', 'retrying', 'succeeded', 'failed'])->default('pending');
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();

            $table->unique(['dispatch_uuid', 'destination_id']);
            $table->index(['webhook_event_id', 'status']);
            $table->index(['status', 'next_attempt_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
