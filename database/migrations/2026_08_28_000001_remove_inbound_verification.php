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
     * ADR-026 Decision 4 — inbound verification is removed from the product
     * entirely. Two things, order not load-bearing: delete every
     * `proxy_secrets` row of purpose `verification` (current and superseded
     * alike — these are secrets issued by upstream providers, PRD-10 AC26,
     * never generated or displayed by this product), then drop the two
     * `proxies` columns that configured it.
     *
     * `2026_08_27_000001_add_sensitive_data_handling_schema.php` (T1) is not
     * edited — a database that has already run it will not run it again, so
     * the two columns would survive silently on any developer's working
     * database that skipped this migration, and the row deletion cannot be
     * expressed as an edit to a create-table migration in any case. One
     * migration, not a two-step expand-and-contract: the columns are read
     * only by code deleted in the same change (T53), and item #10 has never
     * merged to `main`, so no deployed instance reads them at all.
     *
     * `proxies.sensitive_fields` and `destinations.credential_header_name`/
     * `credential_secret`/`credential_set_at` are not touched — they were
     * added by the same T1 migration but belong to capabilities that
     * survive.
     */
    public function up(): void
    {
        DB::table('proxy_secrets')->where('purpose', 'verification')->delete();

        Schema::table('proxies', function (Blueprint $table) {
            $table->dropColumn(['verification_scheme', 'verification_header_name']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Restores exactly the two columns, `verification_scheme` and
     * `verification_header_name`, matching their original definitions.
     * `down()` cannot restore, and says so here rather than leaving a
     * rollback to imply otherwise:
     *
     * - The column VALUES — every proxy's chosen scheme and header name. A
     *   rolled-back database has both columns `NULL` on every row.
     * - The DELETED SECRETS — every `proxy_secrets` row of purpose
     *   `verification` is gone permanently. These are secrets issued by
     *   upstream providers (PRD-10 AC26), never generated or displayed by
     *   this product, and impossible to reconstruct.
     * - The CODE — a rolled-back schema has no reader, no writer and no
     *   surface for either column (T53 already removed all three).
     *
     * This mirrors `2026_08_27_000001`'s own `down()` docblock precedent:
     * reversible in schema, irreversible in substance.
     */
    public function down(): void
    {
        Schema::table('proxies', function (Blueprint $table) {
            $table->string('verification_scheme', 32)->nullable();
            $table->string('verification_header_name', 128)->nullable();
        });
    }
};
