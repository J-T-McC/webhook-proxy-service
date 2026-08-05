<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Idempotency guard against the queue's inherent at-least-once redelivery
     * (ADR-011 Decision 4, AC9). UNIQUE(ingest_id, destination_id, attempt_number)
     * lets `DeliverToDestination` treat a redelivery as a no-op instead of writing a
     * duplicate attempt. Safe on existing data: pre-#4 there is at most one attempt per
     * (ingest_id, destination_id) (attempt_number always 1), so nothing blocks the index.
     * All existing indexes (ingest_id, (team_id, created_at), (proxy_id, status)) are kept.
     */
    public function up(): void
    {
        Schema::table('delivery_attempts', function (Blueprint $table) {
            $table->unique(['ingest_id', 'destination_id', 'attempt_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_attempts', function (Blueprint $table) {
            $table->dropUnique(['ingest_id', 'destination_id', 'attempt_number']);
        });
    }
};
