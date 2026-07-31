<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('proxies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('mode', ['simple', 'enhanced'])->default('simple');

            // SHA-256 of the plaintext ingest token — fixed BINARY(32), single-column
            // UNIQUE for O(1) inbound lookup (ADR-006 perf addendum). The unique index
            // is intentionally NOT scoped to deleted_at: a retired proxy keeps its hash
            // slot so a token is never silently re-issued.
            $table->binary('ingest_token_hash', 32, true)->unique();

            // Plaintext token stored encrypted at rest (decrypted server-side for display).
            $table->text('ingest_token');

            $table->timestamps();
            $table->softDeletes();
            // team_id is indexed via the foreign-key constraint above.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proxies');
    }
};
