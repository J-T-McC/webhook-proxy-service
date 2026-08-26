<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Four composite indexes for item #11's analytics queries (plan-11 §
     * Data Model, Owner-approval flag 2, approved exactly as enumerated) —
     * additive only, nothing else in the schema changes. Column order is
     * deliberate: the grain column leads (equality), `status` follows (a
     * two-value `IN`), `updated_at` ranges last, matching how every
     * analytics query filters (plan-11 § Data Model / § Technical rulings
     * 1). `delivery_attempts (proxy_id, status)` is left in place even
     * though `(proxy_id, status, updated_at)` now makes it a strict prefix
     * — reclaiming it is a separate, later decision with its own gate.
     */
    public function up(): void
    {
        Schema::table('delivery_attempts', function (Blueprint $table) {
            $table->index(['team_id', 'status', 'updated_at']);
            $table->index(['proxy_id', 'status', 'updated_at']);
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->index(['team_id', 'status', 'updated_at']);
            $table->index(['proxy_id', 'status', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * `deliveries.team_id` and `deliveries.proxy_id` had no index of their own
     * before this migration — only the single-column index InnoDB creates
     * automatically to support a `constrained()` foreign key when no other
     * index covers it. Adding this migration's composite indexes makes that
     * automatic single-column index redundant, and InnoDB silently drops it
     * as part of the same `ALTER TABLE` (verified empirically against MySQL
     * 8.4: `SHOW CREATE TABLE` shows no separate `deliveries_team_id_foreign`
     * / `deliveries_proxy_id_foreign` key after `up()`, only the new composite
     * ones). So on `deliveries`, dropping the composite index outright fails
     * with error 1553 ("needed in a foreign key constraint") — nothing else
     * in the table can service the FK. Rollback here therefore restores an
     * equivalent single-column index first, mirroring what InnoDB had created
     * implicitly, before dropping the composite one — this is what makes
     * `down()` actually reversible rather than merely stated to be.
     * `delivery_attempts` needs no such restoration: its pre-existing
     * `(team_id, created_at)` and `(proxy_id, status)` indexes already cover
     * both foreign keys independently of the new composite indexes.
     *
     * The two restored single-column indexes are added only if not already
     * present (`Schema::hasIndex()`), so `down()` stays safe to run more than
     * once against the same database — e.g. parallel test workers reuse a
     * persisted schema across suite runs, and a repeat rollback must not
     * fail with "Duplicate key name" on an index it already restored.
     */
    public function down(): void
    {
        Schema::table('delivery_attempts', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'status', 'updated_at']);
            $table->dropIndex(['proxy_id', 'status', 'updated_at']);
        });

        Schema::table('deliveries', function (Blueprint $table) {
            if (! Schema::hasIndex('deliveries', ['team_id'])) {
                $table->index(['team_id']);
            }

            if (! Schema::hasIndex('deliveries', ['proxy_id'])) {
                $table->index(['proxy_id']);
            }
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'status', 'updated_at']);
            $table->dropIndex(['proxy_id', 'status', 'updated_at']);
        });
    }
};
