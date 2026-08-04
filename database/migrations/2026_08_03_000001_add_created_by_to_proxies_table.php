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
        Schema::table('proxies', function (Blueprint $table) {
            // Creator attribution (ADR-009 Amendment A4). Nullable: pre-feature rows
            // and any no-actor creation stay null (no fabricated backfill) and fall
            // back to Admin/Owner-only management. nullOnDelete (not cascade): the
            // team owns the proxy, so it must survive its creator's account removal.
            $table->foreignId('created_by')->nullable()->after('team_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('proxies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
