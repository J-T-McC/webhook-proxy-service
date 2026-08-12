<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Per-proxy retry policy overrides (ADR-015 Decision 3, AC2). Both columns
     * are nullable with NO schema default: NULL means "system default" —
     * `App\Services\RetryPolicy` is the single resolver of the effective
     * value, mirroring `ResponseResolver`'s pattern for `response_status`/
     * `response_body`. Existing rows read NULL/NULL with no backfill (AC1's
     * "wherever nothing is configured"). `retry_attempt_limit` is a plain
     * `TINYINT UNSIGNED`; range (1-10) is application-validated by
     * `RetryPolicy`/the proxy form, not a schema constraint.
     */
    public function up(): void
    {
        Schema::table('proxies', function (Blueprint $table) {
            $table->unsignedTinyInteger('retry_attempt_limit')->nullable()->after('processing_mode');
            $table->string('retry_backoff_strategy')->nullable()->after('retry_attempt_limit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('proxies', function (Blueprint $table) {
            $table->dropColumn(['retry_attempt_limit', 'retry_backoff_strategy']);
        });
    }
};
