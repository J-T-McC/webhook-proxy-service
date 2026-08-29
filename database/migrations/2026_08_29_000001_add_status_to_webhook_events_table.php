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
     * The event queue view's stored dispatch-progress signal
     * (`App\Enums\WebhookEventStatus`). Written by `ProcessIngestedWebhook`
     * only, for the original dispatch — see that class's docblock. Defaults
     * `pending` for every new row; existing rows are backfilled below rather
     * than left to all read as `pending` (a deployed database already has
     * events that predate this column).
     *
     * `(team_id, id)` is added because the team-wide queue orders by `id`
     * descending across every proxy the team owns — `(team_id, created_at)`
     * (added by `create_webhook_events_table`) does not serve an ORDER BY id.
     */
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->enum('status', ['pending', 'dispatched'])->default('pending')->after('payload_cleaned_at');
            $table->index(['team_id', 'id']);
        });

        // Backfill: `dispatched` wherever an original delivery row exists, or
        // (the pre-`deliveries`-table / pre-replay-feature legacy shape) a
        // `delivery_attempts` row exists for the event's `ingest_id` — every
        // such row can only be from an original send, since replay did not
        // exist yet when it was written. Everything else is a genuine,
        // never-dispatched backlog row and is correctly left `pending`.
        DB::table('webhook_events')
            ->where(function ($query) {
                $query->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('deliveries')
                        ->whereColumn('deliveries.webhook_event_id', 'webhook_events.id')
                        ->where('deliveries.kind', 'original');
                })->orWhereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('delivery_attempts')
                        ->whereColumn('delivery_attempts.ingest_id', 'webhook_events.ingest_id');
                });
            })
            ->update(['status' => 'dispatched']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'id']);
            $table->dropColumn('status');
        });
    }
};
