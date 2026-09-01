<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The outcome of a destination's most recent validation send (#18 AC35,
     * review-18 finding 6). Approved by the Project Owner on 2026-08-31 in the
     * ruling on that finding.
     *
     * AC35 exists so a member can tell three situations apart: the challenge
     * never arrived, it arrived and was rejected, or nobody has opened it yet.
     * They have completely different remedies — fix the URL, versus find the
     * person — and without these columns all three render identically.
     *
     * Exactly one of the pair is ever set. Every send writes one and clears the
     * other, so a row always describes a single attempt rather than fragments
     * of two. Both are nullable because a destination that has never been sent
     * a challenge has no outcome, and because a URL change clears them along
     * with the nonce and the challenge timestamps: the outcome describes a send
     * to the old address and would misdescribe the new one.
     *
     * This is not a fifth validation state. PRD-18 ruled a `send-failed` state
     * out on the grounds that a failed send is the outcome of an action, not a
     * condition of the destination — the gate still reads `validation_state`
     * and nothing else.
     */
    public function up(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->unsignedSmallInteger('validation_last_send_status')
                ->nullable()
                ->after('validation_nonce');
            $table->string('validation_last_send_failure')
                ->nullable()
                ->after('validation_last_send_status');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Dropping these loses the recorded outcomes and returns the captions to
     * their pre-AC35 wording. Nothing else depends on them: no gate, no query
     * and no state derivation reads either column.
     */
    public function down(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->dropColumn([
                'validation_last_send_status',
                'validation_last_send_failure',
            ]);
        });
    }
};
