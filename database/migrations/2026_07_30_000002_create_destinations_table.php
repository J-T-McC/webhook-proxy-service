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
        Schema::create('destinations', function (Blueprint $table) {
            $table->id();
            // No ON DELETE CASCADE: proxies/destinations are soft-deleted, never
            // hard-deleted, so a row cascade never fires. FK stays plain (RESTRICT).
            $table->foreignId('proxy_id')->constrained();
            $table->foreignId('team_id')->constrained();
            $table->string('url');
            $table->enum('http_method', ['POST', 'PUT']);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('destinations');
    }
};
