<?php

namespace App\Modules\Health\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-inserts HealthKit batches. Bypasses Eloquent for the insert path
 * because batch sizes go up to 500 rows per call and we don't need
 * model events on this table.
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
            return [
                'user_id' => $userId,
                'type' => $m['type'],
                'value' => (float) $m['value'],
                'unit' => $m['unit'],
                'source' => $m['source'] ?? 'healthkit',
                'recorded_at' => CarbonImmutable::parse($m['recorded_at'])->toDateTimeString(),
                'metadata' => isset($m['metadata']) ? json_encode($m['metadata']) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $metrics);

        DB::table('health_metrics')->insert($rows);

        return count($rows);
    }
}
