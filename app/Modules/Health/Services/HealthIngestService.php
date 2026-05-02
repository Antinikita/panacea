<?php

namespace App\Modules\Health\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ingests HealthKit-style metric batches with per-day upsert semantics.
 *
 * Each row is keyed on (user_id, type, recorded_on). A second upload for
 * the same day overwrites the value (latest reading wins), preserving
 * yesterday's row so daily trend charts remain accurate.
 */
class HealthIngestService
{
    public function ingest(int $userId, array $metrics): int
    {
        if ($metrics === []) {
            return 0;
        }

        $now = now();

        $rows = array_map(function (array $m) use ($userId, $now) {
            $recordedAt = CarbonImmutable::parse($m['recorded_at']);

            return [
                'user_id' => $userId,
                'type' => $m['type'],
                'value' => (float) $m['value'],
                'unit' => $m['unit'],
                'recorded_at' => $recordedAt->toDateTimeString(),
                'recorded_on' => $recordedAt->toDateString(),
                'metadata' => isset($m['metadata']) ? json_encode($m['metadata']) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $metrics);

        // Upsert per (user_id, type, recorded_on). Eloquent translates this
        // to INSERT ... ON CONFLICT DO UPDATE on Postgres.
        DB::table('health_metrics')->upsert(
            $rows,
            ['user_id', 'type', 'recorded_on'],
            ['value', 'unit', 'recorded_at', 'metadata', 'updated_at'],
        );

        return count($rows);
    }
}
