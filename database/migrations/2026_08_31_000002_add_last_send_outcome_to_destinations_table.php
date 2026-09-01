<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The outcome of a destination's most recent validation send (#18 AC35),
     * so "never arrived", "arrived and was rejected" and "nobody has opened it"
     * read differently. Exactly one of the pair is ever set. Not a fifth
     * validation state — see plan-18 § Data Model.
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
     * Reverse the migrations. Safe: no gate, query or state derivation reads
     * either column.
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
