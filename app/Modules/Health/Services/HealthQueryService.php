<?php

namespace App\Modules\Health\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Health\Models\HealthMetric;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Read-side queries for health_metrics. Exposes both raw row access
 * and rolled-up aggregates (daily averages / sums) for the React +
 * Swift dashboards, plus a compact recent-snapshot for the AI module
 * to enrich the chat profile.
 */
class HealthQueryService
{
    /**
     * @return array<int, array{type: string, value: float, unit: string, recorded_at: string}>
     */
    public function range(int $userId, ?string $type, ?string $from, ?string $to, int $limit = 1000): array
    {
        $query = HealthMetric::where('user_id', $userId);

        if ($type) {
            $query->where('type', $type);
        }
        if ($from) {
            $query->where('recorded_at', '>=', CarbonImmutable::parse($from));
        }
        if ($to) {
            $query->where('recorded_at', '<=', CarbonImmutable::parse($to));
        }

        return $query->orderBy('recorded_at', 'desc')
            ->limit($limit)
            ->get(['type', 'value', 'unit', 'recorded_at'])
            ->map(fn ($m) => [
                'type' => $m->type,
                'value' => (float) $m->value,
                'unit' => $m->unit,
                'recorded_at' => $m->recorded_at->toIso8601String(),
            ])
            ->all();
    }

    /**
     * One-day rollup for the dashboard summary card.
     *
     * Now that ingest is per-day-upsert, there's at most one row per
     * (user, type, day), so we read that row's value directly. Each
     * metric ships with its norm-derived `status` so the frontend
     * doesn't have to compute it.
     */
    public function summaryFor(int $userId, string $date): array
    {
        $day = CarbonImmutable::parse($date)->toDateString();

        // Eloquent (not DB::table) so the 'encrypted' cast on `value` decrypts.
        $rows = HealthMetric::where('user_id', $userId)
            ->where('recorded_on', $day)
            ->get(['type', 'value', 'unit'])
            ->keyBy('type');

        $user = User::find($userId);
        $norms = $user ? HealthNorms::forUser($user) : [];

        $shape = function (string $type) use ($rows, $norms): ?array {
            if (!isset($rows[$type])) {
                return null;
            }
            $value = (float) $rows[$type]->value;
            $norm = $norms[$type] ?? null;

            return [
                'value' => $value,
                'unit' => $rows[$type]->unit,
                'status' => $norm ? HealthNorms::statusFor($type, $value, $norm) : null,
            ];
        };

        return [
            'date' => $day,
            'steps' => $shape('steps'),
            'heart_rate' => $shape('heart_rate'),
            'sleep_duration' => $shape('sleep_duration'),
        ];
    }

    /**
     * Rich recent-window snapshot used both as the AI's profile.metrics
     * payload and as the frozen `health_context` stored on anamneses.
     *
     * Per metric type:
     *   - value   today's reading (the latest row, since ingest is per-day-upsert)
     *   - avg_7d  average over the past N days
     *   - unit    "count" / "bpm" / "minutes"
     *   - status  HealthNorms verdict ("below" | "normal" | "above")
     *   - norm    the user's age/sex-keyed reference range
     *
     * Returns an empty array when the user has no recorded metrics in the
     * window — preserves the AI's "no health context" behavior so it
     * doesn't hallucinate values.
     *
     * @return array<string, array{value: float, avg_7d: float|null, unit: string, status: ?string, norm: array}>
     */
    public function recentSnapshot(User $user, int $days = 7): array
    {
        $today = CarbonImmutable::now()->toDateString();
        $since = CarbonImmutable::now()->subDays($days)->toDateString();

        // Today's row per type (ingest is per-day-upsert, so at most one row).
        // Eloquent so the 'encrypted' cast on `value` runs on read.
        $todayRows = HealthMetric::where('user_id', $user->id)
            ->where('recorded_on', $today)
            ->get(['type', 'value', 'unit'])
            ->keyBy('type');

        // 7-day window averages must be computed in PHP because the value
        // column is now encrypted ciphertext on disk; SQL AVG over base64
        // is meaningless. Window is at most ~21 rows (3 types × 7 days).
        $avg7d = HealthMetric::where('user_id', $user->id)
            ->where('recorded_on', '>=', $since)
            ->get(['type', 'value'])
            ->groupBy('type')
            ->map(fn ($group) => $group->avg(fn ($m) => (float) $m->value));

        if ($todayRows->isEmpty() && $avg7d->isEmpty()) {
            return [];
        }

        $norms = HealthNorms::forUser($user);
        $snapshot = [];

        foreach (['steps', 'heart_rate', 'sleep_duration'] as $type) {
            $today = $todayRows[$type] ?? null;
            $weekly = $avg7d[$type] ?? null;

            // Skip metrics with no data in either window.
            if (!$today && $weekly === null) {
                continue;
            }

            $value = $today ? (float) $today->value : null;
            $weeklyAvg = $weekly !== null ? round((float) $weekly, 1) : null;
            $norm = $norms[$type] ?? null;

            $snapshot[$type] = [
                'value' => $value,
                'avg_7d' => $weeklyAvg,
                'unit' => $today->unit ?? $norm['unit'] ?? '',
                'status' => ($value !== null && $norm) ? HealthNorms::statusFor($type, $value, $norm) : null,
                'norm' => $norm ?: null,
            ];
        }

        return $snapshot;
    }
}
