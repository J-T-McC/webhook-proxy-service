<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Payload-free by construction (ADR-003): there is no body/payload column and
     * no deleted_at — a delivery attempt is an immutable, always-retained fact.
     */
    public function up(): void
    {
        Schema::create('delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained();
            $table->foreignId('proxy_id')->constrained();
            $table->foreignId('destination_id')->constrained();
            $table->uuid('ingest_id');
            $table->enum('status', ['dispatched', 'succeeded', 'failed']);
            $table->smallInteger('http_status')->nullable();
            $table->string('error_summary', 250)->nullable();
            $table->integer('attempt_number')->default(1);
            $table->timestamp('started_at');
            $table->integer('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'created_at']);
            $table->index(['proxy_id', 'status']);
            $table->index('ingest_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_attempts');
    }
};
