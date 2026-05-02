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

        $rows = DB::table('health_metrics')
            ->where('user_id', $userId)
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
