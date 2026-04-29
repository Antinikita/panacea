<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Health metrics module. Backend for the HealthKit data the
 * Bagyt iOS app collects (steps, heart_rate, sleep_*) but currently has
 * nowhere to send.
 *
 * Schema is intentionally generic (type/value/unit/recorded_at) rather
 * than per-metric columns so adding new metric types later doesn't
 * require a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->double('value');
            $table->string('unit', 32);
            $table->string('source', 64)->default('healthkit');
            $table->timestampTz('recorded_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type', 'recorded_at']);
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_metrics');
    }
};
