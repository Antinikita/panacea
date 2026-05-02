<?php

namespace App\Modules\Health\Services;

use App\Modules\Auth\Models\User;

/**
 * Static reference ranges for health metrics, keyed by age bracket + sex.
 *
 * For comparison/educational use only. Sources (consulted, simplified):
 *  - Steps targets: WHO Global Action Plan on Physical Activity
 *    (~7,000–10,000 steps/day for adults).
 *  - Resting heart rate by age + sex: American Heart Association /
 *    Mayo Clinic resting-HR tables (60–100 bpm normal range; sex/age
 *    affects the average within that range).
 *  - Sleep duration: NIH Sleep Health Foundation by-age guidelines.
 *
 * NOT a medical-grade dataset. The diploma copy makes this clear.
 */
class HealthNorms
{
    /**
     * @return array<string, array{min?: float, max?: float, target?: float, avg?: float, low?: float, high?: float, unit: string, label: string}>
     */
    public static function forUser(User $user): array
    {
        $age = (int) ($user->age ?? 30);
        $sex = $user->sex ?? 'male';

        return [
            'steps' => self::stepsNorm($age),
            'heart_rate' => self::heartRateNorm($age, $sex),
            'sleep_duration' => self::sleepNorm($age),
        ];
    }

    private static function stepsNorm(int $age): array
    {
        // Steps targets are mostly age-monotonic — younger = higher target.
        if ($age < 18) {
            return ['low' => 6000, 'target' => 12000, 'high' => 16000, 'unit' => 'count', 'label' => 'Children/teens'];
        }
        if ($age < 50) {
            return ['low' => 5000, 'target' => 8000, 'high' => 12000, 'unit' => 'count', 'label' => 'Adults'];
        }
        if ($age < 65) {
            return ['low' => 4000, 'target' => 7000, 'high' => 10000, 'unit' => 'count', 'label' => 'Adults 50–64'];
        }

        return ['low' => 3000, 'target' => 5000, 'high' => 8000, 'unit' => 'count', 'label' => 'Older adults'];
    }

    private static function heartRateNorm(int $age, string $sex): array
    {
        // Resting heart-rate ranges from Mayo Clinic by age band.
        // The "average" tracks lower for athletes; we use general-population mid.
        $bracket = match (true) {
            $age < 25 => ['min' => 60, 'max' => 84, 'avg' => 70, 'label' => '18–24'],
            $age < 35 => ['min' => 60, 'max' => 82, 'avg' => 70, 'label' => '25–34'],
            $age < 45 => ['min' => 62, 'max' => 84, 'avg' => 71, 'label' => '35–44'],
            $age < 55 => ['min' => 63, 'max' => 86, 'avg' => 72, 'label' => '45–54'],
            $age < 65 => ['min' => 64, 'max' => 86, 'avg' => 73, 'label' => '55–64'],
            default => ['min' => 65, 'max' => 88, 'avg' => 74, 'label' => '65+'],
        };

        // Females tend ~3 bpm higher on average within the same age bracket.
        if ($sex === 'female') {
            $bracket['min'] += 2;
            $bracket['max'] += 2;
            $bracket['avg'] += 3;
        }

        $bracket['unit'] = 'bpm';
        $bracket['label'] = "Resting HR for {$bracket['label']} ".self::sexLabel($sex);

        return $bracket;
    }

    private static function sleepNorm(int $age): array
    {
        // NIH age-band sleep duration. Returned in minutes to match the
        // existing sleep_duration metric unit.
        [$minH, $maxH, $label] = match (true) {
            $age < 13 => [9.0, 11.0, 'Children'],
            $age < 18 => [8.0, 10.0, 'Teens'],
            $age < 65 => [7.0, 9.0, 'Adults'],
            default => [7.0, 8.0, 'Older adults'],
        };

        return [
            'min' => $minH * 60,
            'max' => $maxH * 60,
            'avg' => (($minH + $maxH) / 2) * 60,
            'unit' => 'minutes',
            'label' => "{$label}: {$minH}–{$maxH} h",
        ];
    }

    private static function sexLabel(string $sex): string
    {
        return match ($sex) {
            'female' => 'female',
            'other' => '',
            default => 'male',
        };
    }

    /**
     * Compares a value against the user's norm and returns a status tag
     * the frontend uses to colour the "vs norm" badge on the Dashboard.
     *
     * Steps use target/low/high; heart_rate + sleep_duration use min/max.
     */
    public static function statusFor(string $type, float $value, array $norm): ?string
    {
        if ($type === 'steps') {
            if (isset($norm['low']) && $value < $norm['low']) {
                return 'below';
            }
            if (isset($norm['high']) && $value > $norm['high']) {
                return 'above';
            }
            return 'normal';
        }

        if (isset($norm['min']) && $value < $norm['min']) {
            return 'below';
        }
        if (isset($norm['max']) && $value > $norm['max']) {
            return 'above';
        }

        return 'normal';
    }
}
