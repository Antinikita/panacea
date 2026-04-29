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

it('returns the summary for a given day with aggregated steps + avg heart rate', function () {
    $this->postJson('/api/health/metrics', [
        'metrics' => [
            ['type' => 'steps', 'value' => 1000, 'unit' => 'count', 'recorded_at' => '2026-04-28T08:00:00Z'],
            ['type' => 'steps', 'value' => 2000, 'unit' => 'count', 'recorded_at' => '2026-04-28T15:00:00Z'],
            ['type' => 'heart_rate', 'value' => 60, 'unit' => 'bpm', 'recorded_at' => '2026-04-28T08:00:00Z'],
            ['type' => 'heart_rate', 'value' => 80, 'unit' => 'bpm', 'recorded_at' => '2026-04-28T15:00:00Z'],
        ],
    ])->assertCreated();

    $response = $this->getJson('/api/health/summary?date=2026-04-28');

    $response->assertOk()
        ->assertJsonPath('date', '2026-04-28');

    expect($response->json('steps'))->toEqual(3000)
        ->and($response->json('avg_heart_rate'))->toEqual(70);
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
        'source' => 'healthkit',
        'recorded_at' => '2026-04-28 08:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->getJson('/api/health/metrics?type=steps');

    $response->assertOk()->assertJsonPath('count', 0);
});

it('exposes the recent snapshot via HealthQueryService for AI profile enrichment', function () {
    $this->postJson('/api/health/metrics', [
        'metrics' => [
            ['type' => 'steps', 'value' => 5000, 'unit' => 'count', 'recorded_at' => now()->subDay()->toIso8601String()],
            ['type' => 'heart_rate', 'value' => 65, 'unit' => 'bpm', 'recorded_at' => now()->subDay()->toIso8601String()],
            ['type' => 'heart_rate', 'value' => 75, 'unit' => 'bpm', 'recorded_at' => now()->subHours(6)->toIso8601String()],
        ],
    ])->assertCreated();

    $service = app(\App\Modules\Health\Services\HealthQueryService::class);
    $snapshot = $service->recentSnapshot($this->user->id, 7);

    expect($snapshot)
        ->toHaveKey('steps')
        ->toHaveKey('heart_rate')
        ->and($snapshot['steps'])->toEqual(5000)
        ->and($snapshot['heart_rate'])->toEqual(70);
});
