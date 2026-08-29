<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Item #10's data-model gate (Owner-approval flag 1, approved exactly as
     * enumerated — plan-10 § Data Model). One new table, `proxy_secrets`, for
     * the two rotating secret purposes (verification, signing — ADR-021
     * Decision 2), plus configuration columns on `proxies` and `destinations`.
     * Additive only: no backfill, no default written to any existing row, no
     * index touched on either existing table.
     *
     * `proxy_secrets`'s `UNIQUE(proxy_id, purpose, is_current)` is a
     * partial-unique constraint — MySQL and SQLite both ignore NULLs in a
     * unique index, so any number of superseded rows (`is_current = NULL`)
     * coexist, while at most one current (`is_current = true`) row per
     * `(proxy_id, purpose)` is enforced by the database (AC29's cap of two is
     * `SecretStore`'s write-path property, not this constraint's).
     */
    public function up(): void
    {
        Schema::create('proxy_secrets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained();
            $table->foreignId('proxy_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 32);
            $table->text('value');
            $table->boolean('is_current')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['proxy_id', 'purpose', 'is_current'],
                'proxy_secrets_proxy_id_purpose_is_current_unique',
            );
        });

        Schema::table('proxies', function (Blueprint $table) {
            $table->string('verification_scheme', 32)->nullable();
            $table->string('verification_header_name', 128)->nullable();
            $table->longText('sensitive_fields')->nullable();
        });

        Schema::table('destinations', function (Blueprint $table) {
            $table->string('credential_header_name', 128)->nullable();
            $table->text('credential_secret')->nullable();
            $table->timestamp('credential_set_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Destructive to every stored secret — `proxy_secrets` and the two
     * encrypted columns dropped here cannot be recovered afterwards
     * (plan-10 § Data Model, stated rather than left implicit).
     */
    public function down(): void
    {
        Schema::dropIfExists('proxy_secrets');

        Schema::table('proxies', function (Blueprint $table) {
            $table->dropColumn(['verification_scheme', 'verification_header_name', 'sensitive_fields']);
        });

        Schema::table('destinations', function (Blueprint $table) {
            $table->dropColumn(['credential_header_name', 'credential_secret', 'credential_set_at']);
        });
    }
};
