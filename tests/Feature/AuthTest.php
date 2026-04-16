<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

test('register hashes password exactly once', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'secret12',
        'password_confirmation' => 'secret12',
    ]);

    $response->assertStatus(201);

    expect(Auth::attempt(['email' => 'test@example.com', 'password' => 'secret12']))
        ->toBeTrue();
});

test('register does not issue token for SPA requests', function () {
    $response = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
        ->postJson('/api/register', [
            'name' => 'SPA User',
            'email' => 'spa@example.com',
            'password' => 'secret12',
            'password_confirmation' => 'secret12',
        ]);

    $response->assertStatus(201);
    $response->assertJsonMissing(['token']);
    expect($response->json('token'))->toBeNull();
});

test('register issues token for mobile (no X-Requested-With)', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Mobile User',
        'email' => 'mobile@example.com',
        'password' => 'secret12',
        'password_confirmation' => 'secret12',
    ]);

    $response->assertStatus(201);
    expect($response->json('token'))->toBeString()->not->toBeEmpty();
});

test('login does not issue token for SPA requests', function () {
    $user = User::factory()->create(['password' => 'secret12']);

    $response = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
        ->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret12',
        ]);

    $response->assertStatus(200);
    expect($response->json('token'))->toBeNull();
});

test('login issues token for mobile requests', function () {
    $user = User::factory()->create(['password' => 'secret12']);

    $response = $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'secret12',
    ]);

    $response->assertStatus(200);
    expect($response->json('token'))->toBeString()->not->toBeEmpty();
});

test('login returns 422 on wrong credentials', function () {
    $user = User::factory()->create(['password' => 'secret12']);

    $response = $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(422);
});

test('logout for mobile deletes only the current token', function () {
    $user = User::factory()->create();
    $currentToken = $user->createToken('mobile')->plainTextToken;
    $otherToken = $user->createToken('other-device')->plainTextToken;

    expect(PersonalAccessToken::where('tokenable_id', $user->id)->count())->toBe(2);

    $response = $this->withHeader('Authorization', 'Bearer ' . $currentToken)
        ->postJson('/api/logout');

    $response->assertStatus(200);

    $remaining = PersonalAccessToken::where('tokenable_id', $user->id)->get();
    expect($remaining)->toHaveCount(1);
    expect($remaining->first()->name)->toBe('other-device');
});
