<?php

namespace App\Console\Commands;

use App\Modules\Auth\Models\User;
use App\Modules\Health\Services\HealthIngestService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Seeds 30 days of plausible health metrics for one or all users so the
 * trend charts, comparison bars, and AI snapshot have something to render.
 *
 * Patterns:
 *   - Steps trend up over the window with a weekly weekend dip + noise.
 *   - Heart rate jitters around a mean with a slight upward drift.
 *   - Sleep hovers in the normal band with a few short nights for contrast.
 *
 * Idempotent — repeated runs hit the (user_id, type, recorded_on) unique
 * key and overwrite the previous values.
 */
class SeedHealthData extends Command
{
    protected $signature = 'health:seed
                            {--user-id= : Seed for one user (omit to seed for all)}
                            {--days=30 : How many days back to fill}';

    protected $description = 'Seed varied health metrics so the Health page has graphs to render';

    public function handle(HealthIngestService $ingest): int
    {
        $days = (int) $this->option('days');
        $userId = $this->option('user-id');

        $users = $userId
            ? User::query()->whereKey($userId)->get()
            : User::query()->get();

        if ($users->isEmpty()) {
            $this->error('No users found.');
            return self::FAILURE;
        }

        $today = CarbonImmutable::today();
        $total = 0;

        foreach ($users as $user) {
            $rows = [];

            for ($i = $days - 1; $i >= 0; $i--) {
                $day = $today->subDays($i);
                $isWeekend = in_array($day->dayOfWeek, [0, 6], true);
                $progress = ($days - 1 - $i) / max(1, $days - 1); // 0..1

                // Steps: 6500 base, +1500 over the window, ±1200 noise, weekend −1500.
                $stepsBase = 6500 + (1500 * $progress);
                $stepsNoise = mt_rand(-1200, 1200);
                $steps = max(1500, (int) ($stepsBase + $stepsNoise - ($isWeekend ? 1500 : 0)));

                // HR: 68 base, slight +3 drift, ±5 noise.
                $hrBase = 68 + (3 * $progress);
                $hr = (int) ($hrBase + mt_rand(-5, 5));

                // Sleep: 7h centered, ±60 min noise, every 5th day clipped to 5–6h for variety.
                $sleepBase = 420;
                $sleep = $sleepBase + mt_rand(-50, 60);
                if ($i % 5 === 0) {
                    $sleep = mt_rand(310, 360);
                }

                $stamp = $day->setTime(22, 0, 0)->toIso8601String();
                $rows[] = ['type' => 'steps',          'value' => $steps, 'unit' => 'count',   'recorded_at' => $stamp];
                $rows[] = ['type' => 'heart_rate',     'value' => $hr,    'unit' => 'bpm',     'recorded_at' => $stamp];
                $rows[] = ['type' => 'sleep_duration', 'value' => $sleep, 'unit' => 'minutes', 'recorded_at' => $stamp];
            }

            $count = $ingest->ingest($user->id, $rows);
            $total += $count;
            $this->line("  → {$user->email}: {$count} metrics");
        }

        $this->info("Seeded {$total} health metrics across {$users->count()} user(s).");

        return self::SUCCESS;
    }
}
