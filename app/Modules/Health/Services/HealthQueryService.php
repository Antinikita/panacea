<?php

namespace App\Modules\Health\Services;

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
     * @return array{date: string, steps: ?float, avg_heart_rate: ?float, sleep_minutes: ?float}
     */
    public function summaryFor(int $userId, string $date): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();
        $next = $day->addDay();

        $rows = DB::table('health_metrics')
            ->where('user_id', $userId)
            ->whereBetween('recorded_at', [$day, $next])
            ->select('type', DB::raw('SUM(value) AS sum_value'), DB::raw('AVG(value) AS avg_value'))
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        return [
            'date' => $day->toDateString(),
            'steps' => isset($rows['steps']) ? (float) $rows['steps']->sum_value : null,
            'avg_heart_rate' => isset($rows['heart_rate']) ? (float) $rows['heart_rate']->avg_value : null,
            'sleep_minutes' => isset($rows['sleep_duration']) ? (float) $rows['sleep_duration']->sum_value : null,
        ];
    }

    /**
     * Compact recent-window snapshot for the AI's profile.metrics field.
     * Returns rolled-up averages over the last $days days; empty array if
     * the user has no recorded metrics in the window so the AI prompt
     * doesn't get noise.
     *
     * @return array<string, float>
     */
    public function recentSnapshot(int $userId, int $days = 7): array
    {
        $since = CarbonImmutable::now()->subDays($days);

        $rows = DB::table('health_metrics')
            ->where('user_id', $userId)
            ->where('recorded_at', '>=', $since)
            ->select('type', DB::raw('AVG(value) AS avg_value'), DB::raw('SUM(value) AS sum_value'))
            ->groupBy('type')
            ->get();

        $snapshot = [];
        foreach ($rows as $r) {
            $snapshot[$r->type] = match ($r->type) {
                'steps', 'sleep_duration' => round((float) $r->sum_value, 1),
                default => round((float) $r->avg_value, 1),
            };
        }

        return $snapshot;
    }
}
