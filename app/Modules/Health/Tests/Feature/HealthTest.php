<?php

use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    seedPermissions();

    $this->user = User::create([
        'name' => 'Healthy',
        'email' => 'healthy@example.com',
        'password' => 'secret123',
        'sex' => 'male',
        'age' => 30,
    ]);
    $this->user->assignRole('user');

    Sanctum::actingAs($this->user);
});

it('ingests a HealthKit batch and persists rows', function () {
    $response = $this->postJson('/api/health/metrics', [
        'metrics' => [
            ['type' => 'steps', 'value' => 1234, 'unit' => 'count', 'recorded_at' => '2026-04-28T08:00:00Z'],
            ['type' => 'heart_rate', 'value' => 72, 'unit' => 'bpm', 'recorded_at' => '2026-04-28T08:00:00Z'],
            ['type' => 'sleep_duration', 'value' => 420, 'unit' => 'minutes', 'recorded_at' => '2026-04-28T08:00:00Z'],
        ],
    ]);

    $response->assertCreated()->assertJsonPath('inserted', 3);
    expect(DB::table('health_metrics')->where('user_id', $this->user->id)->count())->toBe(3);
});

it('rejects a batch missing required fields', function () {
    $this->postJson('/api/health/metrics', [
        'metrics' => [
            ['type' => 'steps', 'value' => 1234], // missing unit, recorded_at
        ],
    ])->assertStatus(422);
});

it('rejects more than 500 metrics per batch', function () {
    $tooMany = array_fill(0, 501, [
        'type' => 'steps',
        'value' => 1,
        'unit' => 'count',
        'recorded_at' => '2026-04-28T08:00:00Z',
    ]);

    $this->postJson('/api/health/metrics', ['metrics' => $tooMany])->assertStatus(422);
});

it('upserts a same-day metric instead of duplicating', function () {
    // Two uploads for the same day → one row, latest wins.
    $this->postJson('/api/health/metrics', [
        'metrics' => [['type' => 'steps', 'value' => 5000, 'unit' => 'count', 'recorded_at' => '2026-04-28T08:00:00Z']],
    ])->assertCreated();

    $this->postJson('/api/health/metrics', [
        'metrics' => [['type' => 'steps', 'value' => 8000, 'unit' => 'count', 'recorded_at' => '2026-04-28T15:00:00Z']],
    ])->assertCreated();

    $rows = DB::table('health_metrics')->where('user_id', $this->user->id)->where('type', 'steps')->get();
    expect($rows)->toHaveCount(1);
    expect((float) $rows[0]->value)->toEqual(8000);
});

it("keeps yesterday's row when today's gets a new reading", function () {
    $this->postJson('/api/health/metrics', [
        'metrics' => [
            ['type' => 'steps', 'value' => 3000, 'unit' => 'count', 'recorded_at' => '2026-04-27T08:00:00Z'],
            ['type' => 'steps', 'value' => 7000, 'unit' => 'count', 'recorded_at' => '2026-04-28T08:00:00Z'],
        ],
    ])->assertCreated();

    expect(DB::table('health_metrics')->where('user_id', $this->user->id)->where('type', 'steps')->count())
        ->toBe(2);
});

it('drops the source attribute on ingest', function () {
    // The payload includes source but the schema no longer has the column.
    // Should succeed because the validator allows source as nullable (kept
    // for backwards compatibility with old iOS clients).
    $this->postJson('/api/health/metrics', [
        'metrics' => [[
            'type' => 'steps',
            'value' => 1000,
            'unit' => 'count',
            'recorded_at' => '2026-04-28T08:00:00Z',
            'source' => 'healthkit',
        ]],
    ])->assertCreated();

    expect(DB::getSchemaBuilder()->hasColumn('health_metrics', 'source'))->toBeFalse();
});

it('returns the summary with status badges from norms', function () {
    $today = now()->toDateString();

    $this->postJson('/api/health/metrics', [
        'metrics' => [
            // 30/male norm: steps low=5000, target=8000, high=12000.
            ['type' => 'steps', 'value' => 2000, 'unit' => 'count', 'recorded_at' => now()->toIso8601String()],
            // 30/male norm: heart_rate min=60, max=82, avg=70 → 72 is normal.
            ['type' => 'heart_rate', 'value' => 72, 'unit' => 'bpm', 'recorded_at' => now()->toIso8601String()],
        ],
    ])->assertCreated();

    $response = $this->getJson("/api/health/summary?date={$today}");

    $response->assertOk()
        ->assertJsonPath('steps.status', 'below')
        ->assertJsonPath('heart_rate.status', 'normal')
        ->assertJsonPath('sleep_duration', null);

    expect($response->json('steps.value'))->toEqual(2000)
        ->and($response->json('heart_rate.value'))->toEqual(72);
});

it('returns norms for the authenticated user age + sex', function () {
    $response = $this->getJson('/api/health/norms');

    $response->assertOk()
        ->assertJsonPath('user.age', 30)
        ->assertJsonPath('user.sex', 'male')
        ->assertJsonPath('norms.steps.target', 8000)
        ->assertJsonPath('norms.steps.unit', 'count')
        ->assertJsonPath('norms.heart_rate.min', 60)
        ->assertJsonPath('norms.heart_rate.max', 82)
        ->assertJsonPath('norms.sleep_duration.unit', 'minutes');
});

it("does not surface another user's metrics", function () {
    $other = User::create([
        'name' => 'Other',
        'email' => 'other@example.com',
        'password' => 'secret123',
    ]);
    $other->assignRole('user');

    DB::table('health_metrics')->insert([
        'user_id' => $other->id,
        'type' => 'steps',
        'value' => 9999,
        'unit' => 'count',
        'recorded_at' => '2026-04-28 08:00:00',
        'recorded_on' => '2026-04-28',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->getJson('/api/health/metrics?type=steps');

    $response->assertOk()->assertJsonPath('count', 0);
});

it('exposes the recent snapshot via HealthQueryService for AI profile enrichment', function () {
    // Set demographics so HealthNorms returns a deterministic range.
    $this->user->update(['age' => 30, 'sex' => 'male']);

    // Two heart_rate readings on different days so the 7-day average is
    // a real average; same-day uploads would upsert into one row and
    // collapse the average to a single point.
    $this->postJson('/api/health/metrics', [
        'metrics' => [
            ['type' => 'steps', 'value' => 5000, 'unit' => 'count', 'recorded_at' => now()->subDay()->toIso8601String()],
            ['type' => 'heart_rate', 'value' => 65, 'unit' => 'bpm', 'recorded_at' => now()->subDays(2)->toIso8601String()],
            ['type' => 'heart_rate', 'value' => 75, 'unit' => 'bpm', 'recorded_at' => now()->toIso8601String()],
        ],
    ])->assertCreated();

    $service = app(\App\Modules\Health\Services\HealthQueryService::class);
    $snapshot = $service->recentSnapshot($this->user, 7);

    // New rich shape: each metric is {value, avg_7d, unit, status, norm}.
    expect($snapshot)
        ->toHaveKey('steps')
        ->toHaveKey('heart_rate')
        ->and($snapshot['heart_rate']['value'])->toEqual(75)       // today's row
        ->and($snapshot['heart_rate']['unit'])->toBe('bpm')
        ->and($snapshot['heart_rate']['status'])->toBe('normal')  // 30/male norm 60-82
        ->and($snapshot['heart_rate']['norm'])->toBeArray()
        ->and($snapshot['heart_rate']['norm']['min'])->toEqual(60)
        ->and($snapshot['heart_rate']['avg_7d'])->toEqual(70)     // (65+75)/2
        // Steps row is from yesterday → today's value is null, avg_7d carries it.
        ->and($snapshot['steps']['avg_7d'])->toEqual(5000)
        ->and($snapshot['steps']['norm']['target'])->toEqual(8000);
});
