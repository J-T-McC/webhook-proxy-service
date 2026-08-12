<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The one non-additive migration step in #6 (plan Risk 2, ✋ flag 6): the
     * ADR-011-approved idempotency key `UNIQUE(ingest_id, destination_id,
     * attempt_number)` is replaced by `UNIQUE(delivery_id, attempt_number)`
     * (ADR-015 Decision 2 / ADR-016 P3). Ordering is load-bearing and must
     * never be reversed or split across migrations: (1) add `delivery_id`
     * nullable — NULL only for pre-#6 rows, no backfill, and MySQL unique
     * semantics never collide two NULLs; (2) add the new unique index; only
     * then (3) drop the old one. All other existing indexes (`ingest_id`,
     * `(team_id, created_at)`, `(proxy_id, status)`) are kept untouched.
     */
    public function up(): void
    {
        Schema::table('delivery_attempts', function (Blueprint $table) {
            $table->foreignId('delivery_id')->nullable()->after('id')->constrained();
        });

        Schema::table('delivery_attempts', function (Blueprint $table) {
            $table->unique(['delivery_id', 'attempt_number']);
        });

        Schema::table('delivery_attempts', function (Blueprint $table) {
            $table->dropUnique(['ingest_id', 'destination_id', 'attempt_number']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * The FK constraint on `delivery_id` is satisfied by the composite unique
     * index (MySQL never created a separate single-column index once the
     * leftmost-prefix composite unique existed), so the FK must be dropped
     * before that index can be dropped — otherwise MySQL rejects the drop
     * with "needed in a foreign key constraint".
     */
    public function down(): void
    {
        Schema::table('delivery_attempts', function (Blueprint $table) {
            $table->unique(['ingest_id', 'destination_id', 'attempt_number']);
        });

        Schema::table('delivery_attempts', function (Blueprint $table) {
            $table->dropForeign(['delivery_id']);
        });

        Schema::table('delivery_attempts', function (Blueprint $table) {
            $table->dropUnique(['delivery_id', 'attempt_number']);
        });

        Schema::table('delivery_attempts', function (Blueprint $table) {
            $table->dropColumn('delivery_id');
        });
    }
};
