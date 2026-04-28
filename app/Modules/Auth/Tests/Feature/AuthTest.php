<?php

use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(fn () => seedPermissions());

it('registers a new user, returns a token, and assigns the user role', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'password' => 'secret123',
        'password_confirmation' => 'secret123',
        'sex' => 'female',
        'age' => 28,
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['token', 'token_type', 'user' => ['id', 'name', 'email'], 'roles', 'permissions']);

    $user = User::where('email', 'alice@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->hasRole('user'))->toBeTrue();
});

it('rejects registration with mismatched password confirmation', function () {
    $this->postJson('/api/register', [
        'name' => 'Bob',
        'email' => 'bob@example.com',
        'password' => 'secret123',
        'password_confirmation' => 'mismatched',
    ])->assertStatus(422);
});

it('logs in with valid credentials and returns a token', function () {
    $user = User::create([
        'name' => 'Carol',
        'email' => 'carol@example.com',
        'password' => 'secret123',
    ]);
    $user->assignRole('user');

    $response = $this->postJson('/api/login', [
        'email' => 'carol@example.com',
        'password' => 'secret123',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['token', 'user' => ['id', 'email'], 'roles', 'permissions']);
});

it('rejects login with the wrong password', function () {
    $user = User::create([
        'name' => 'Dan',
        'email' => 'dan@example.com',
        'password' => 'secret123',
    ]);
    $user->assignRole('user');

    $this->postJson('/api/login', [
        'email' => 'dan@example.com',
        'password' => 'WRONG',
    ])->assertStatus(422);
});

it('revokes the current token on logout', function () {
    $user = User::create([
        'name' => 'Eve',
        'email' => 'eve@example.com',
        'password' => 'secret123',
    ]);
    $user->assignRole('user');

    Sanctum::actingAs($user);

    $this->postJson('/api/logout')->assertOk();
});
