<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Destination validation state (#18, ADR-027 decision 1). Traffic flows
     * only while `validation_state = validated`; enforcement points are in
     * plan-18 § Architecture. `expired` is derived, never stored, so nothing
     * can leave it stale. `validation_nonce` is what makes a signed link
     * single-use — a signature alone is replayable.
     */
    public function up(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->enum('validation_state', ['unvalidated', 'pending', 'validated'])
                ->default('unvalidated')
                ->after('http_method');
            $table->timestamp('validated_at')->nullable()->after('validation_state');
            $table->timestamp('validation_challenge_sent_at')->nullable()->after('validated_at');
            $table->timestamp('validation_challenge_expires_at')->nullable()->after('validation_challenge_sent_at');
            $table->string('validation_nonce')->nullable()->after('validation_challenge_expires_at');
        });

        // PRD-18 AC30, approved by the Project Owner with the PRD: every
        // destination that exists when this runs is grandfathered to
        // `validated`. Forcing revalidation instead would stop delivery on
        // destinations teams already depend on until somebody at each receiving
        // end happened to click a link — a security release becoming an outage.
        //
        // The exemption decays rather than being permanent: editing a
        // grandfathered destination's URL returns it to `unvalidated` under
        // AC5, like any other destination.
        //
        // Soft-deleted rows are included deliberately. A restored destination
        // must come back in the state it left, and excluding them would
        // silently unvalidate a destination whose only offence was being
        // deleted before this migration ran.
        DB::table('destinations')->update([
            'validation_state' => 'validated',
            'validated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * Dropping the columns does not restore a pre-migration notion of which
     * destinations were trusted — there was none. Rolling back returns the
     * product to delivering to every configured destination, which is the
     * behaviour #18 narrowed (ADR-027 § Impact).
     */
    public function down(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->dropColumn([
                'validation_state',
                'validated_at',
                'validation_challenge_sent_at',
                'validation_challenge_expires_at',
                'validation_nonce',
            ]);
        });
    }
};
