<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rebuild health_metrics for per-day upsert semantics.
 *
 * Phase 4 shipped append-only: every iOS launch created fresh rows and
 * SUM(steps) overcounted. We now want one row per (user_id, type, day),
 * with the latest reading replacing the earlier one.
 *
 * Schema delta:
 *   - DROP    `source` column (was always 'healthkit', no value).
 *   - ADD     `recorded_on DATE` populated by the ingest service from
 *             recorded_at::date. Plain column (not generated) because
 *             Postgres rejects timestamptz::date as non-immutable.
 *   - REPLACE the (user_id, type, recorded_at) index with a UNIQUE
 *             (user_id, type, recorded_on) constraint that enforces
 *             the upsert key.
 *   - ADD     (user_id, recorded_on) index for trend-range queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Backfill recorded_on for any existing rows from recorded_at,
        // before adding the NOT NULL + unique constraints.
        Schema::table('health_metrics', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'type', 'recorded_at']);
        });

        Schema::table('health_metrics', function (Blueprint $table) {
            $table->dropColumn('source');
        });

        Schema::table('health_metrics', function (Blueprint $table) {
            $table->date('recorded_on')->nullable();
        });

        // Backfill from existing rows.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('UPDATE health_metrics SET recorded_on = recorded_at::date WHERE recorded_on IS NULL');
        } else {
            DB::statement("UPDATE health_metrics SET recorded_on = date(recorded_at) WHERE recorded_on IS NULL");
        }

        // De-duplicate any rows that would collide on the new unique key
        // (keep the highest id for each user+type+day). Necessary because
        // the old append-only ingest could have created multiple rows for
        // the same day.
        DB::statement(
            'DELETE FROM health_metrics WHERE id NOT IN (
                SELECT * FROM (
                    SELECT MAX(id) FROM health_metrics
                    GROUP BY user_id, type, recorded_on
                ) t
            )'
        );

        Schema::table('health_metrics', function (Blueprint $table) {
            $table->date('recorded_on')->nullable(false)->change();
            $table->unique(['user_id', 'type', 'recorded_on'], 'health_metrics_user_type_day_unique');
            $table->index(['user_id', 'recorded_on'], 'health_metrics_user_day_idx');
        });
    }

    public function down(): void
    {
        Schema::table('health_metrics', function (Blueprint $table) {
            $table->dropUnique('health_metrics_user_type_day_unique');
            $table->dropIndex('health_metrics_user_day_idx');
            $table->dropColumn('recorded_on');
            $table->string('source', 64)->default('healthkit');
            $table->index(['user_id', 'type', 'recorded_at']);
        });
    }
};
