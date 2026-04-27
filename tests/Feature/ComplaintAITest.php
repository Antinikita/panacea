<?php

use App\Models\Complaint;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

test('analyze uses mock when services.ai.use_mock is true', function () {
    config([
        'services.ai.use_mock' => true,
        'services.ai.mock_delay' => 0,
    ]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $complaint = $user->complaints()->create(['complaint' => 'Something hurts']);

    $response = $this->postJson('/api/complaints/analyze', [
        'complaint_id' => $complaint->id,
    ]);

    $response->assertStatus(200);
    $response->assertJsonStructure(['reply', 'recommendation_id', 'saved_at', 'full_response']);
    expect(Recommendation::count())->toBe(1);
});

test('analyze reads AI url from config not env at runtime', function () {
    config([
        'services.ai.use_mock' => false,
        'services.ai.url' => 'https://ai.test/analyze',
        'services.ai.token' => 'test-token',
        'services.ai.timeout' => 5,
    ]);

    Http::fake([
        'ai.test/*' => Http::response([
            'reply' => 'from real ai',
        ], 200),
    ]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $complaint = $user->complaints()->create(['complaint' => 'headache']);

    $response = $this->postJson('/api/complaints/analyze', [
        'complaint_id' => $complaint->id,
    ]);

    $response->assertStatus(200);
    $response->assertJson(['reply' => 'from real ai']);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://ai.test/analyze'
            && $request->hasHeader('X-Service-Token', 'test-token');
    });
});

test('analyze rejects complaints from other users', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    Sanctum::actingAs($other);

    $complaint = $owner->complaints()->create(['complaint' => 'private']);

    $response = $this->postJson('/api/complaints/analyze', [
        'complaint_id' => $complaint->id,
    ]);

    $response->assertStatus(403);
});
