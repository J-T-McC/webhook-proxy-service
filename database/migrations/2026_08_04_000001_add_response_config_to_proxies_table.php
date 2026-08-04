<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * User-defined upstream response configuration (ADR-004, AC1–AC4). Both columns
     * are nullable with NO schema default: NULL means unconfigured, and the `202`
     * default is owned by ResponseResolver (single source, ADR-004) — never written
     * into the schema, so it can never drift. Existing #1 rows inherit `202` with no
     * backfill (AC3, no surprise).
     */
    public function up(): void
    {
        Schema::table('proxies', function (Blueprint $table) {
            $table->unsignedSmallInteger('response_status')->nullable()->after('mode');
            $table->text('response_body')->nullable()->after('response_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('proxies', function (Blueprint $table) {
            $table->dropColumn(['response_status', 'response_body']);
        });
    }
};
