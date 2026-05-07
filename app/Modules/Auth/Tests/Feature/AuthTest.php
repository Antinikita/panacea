<?php

use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    seedPermissions();
    // Test env uses CACHE_STORE=array (per phpunit.xml). Flush it so the
    // throttle and idempotency keys from the previous test don't carry
    // over. auth-strict now uses keys like `ip:127.0.0.1` and
    // `email:<addr>` — clearing per-key would miss the email buckets.
    \Illuminate\Support\Facades\Cache::flush();
});

it('registers a new user, returns a token, and assigns the user role', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'password' => 'T3st!Pass#2026',
        'password_confirmation' => 'T3st!Pass#2026',
        'sex' => 'female',
        'age' => 28,
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['token', 'token_type', 'user' => ['id', 'name', 'email'], 'roles', 'permissions']);

    $user = User::byEmail('alice@example.com');
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

it('rejects registration when the password is too weak', function () {
    $this->postJson('/api/register', [
        'name' => 'Weakling',
        'email' => 'weak@example.com',
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
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

it('exposes a deep health probe that reports ai-service reachability', function () {
    \Illuminate\Support\Facades\Http::fake([
        '*' => \Illuminate\Support\Facades\Http::response(['ok' => true], 200),
    ]);

    // Setting via $_ENV/$_SERVER + putenv covers Laravel's Env repository
    // adapters; relying on putenv alone misses the immutable cache layer.
    $setEnv = function (string $k, string $v) {
        $_ENV[$k] = $v; $_SERVER[$k] = $v; putenv("$k=$v");
    };
    $setEnv('AI_MODULE_URL', 'http://stub.local');
    $setEnv('HEALTH_PROBE_TOKEN', 'test-probe-secret');

    $response = $this->withHeaders(['X-Health-Probe-Token' => 'test-probe-secret'])
        ->getJson('/api/health/deep');

    $response->assertOk()
        ->assertJsonPath('laravel', 'ok')
        ->assertJsonPath('ai_service.status', 'ok');
});

it('rejects /health/deep without the probe token or auth', function () {
    $_ENV['HEALTH_PROBE_TOKEN'] = $_SERVER['HEALTH_PROBE_TOKEN'] = 'test-probe-secret';
    putenv('HEALTH_PROBE_TOKEN=test-probe-secret');
    $this->getJson('/api/health/deep')->assertStatus(401);
});

it('rejects /health/deep with the wrong probe token', function () {
    $_ENV['HEALTH_PROBE_TOKEN'] = $_SERVER['HEALTH_PROBE_TOKEN'] = 'test-probe-secret';
    putenv('HEALTH_PROBE_TOKEN=test-probe-secret');
    $this->withHeaders(['X-Health-Probe-Token' => 'wrong'])
        ->getJson('/api/health/deep')
        ->assertStatus(401);
});

it('allows /health/deep when caller is Sanctum-authenticated', function () {
    \Illuminate\Support\Facades\Http::fake([
        '*' => \Illuminate\Support\Facades\Http::response(['ok' => true], 200),
    ]);
    $_ENV['AI_MODULE_URL'] = $_SERVER['AI_MODULE_URL'] = 'http://stub.local';
    putenv('AI_MODULE_URL=http://stub.local');
    unset($_ENV['HEALTH_PROBE_TOKEN'], $_SERVER['HEALTH_PROBE_TOKEN']);
    putenv('HEALTH_PROBE_TOKEN');

    $user = User::create([
        'name' => 'P',
        'email' => 'probe@example.com',
        'password' => 'oldsecret123',
    ]);
    $user->assignRole('user');
    Sanctum::actingAs($user);

    $this->getJson('/api/health/deep')->assertOk();
});

it('returns an X-Request-Id header on every response', function () {
    $response = $this->getJson('/api/health');

    $response->assertOk();
    expect($response->headers->get('X-Request-Id'))
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
});

it('echoes a valid incoming X-Request-Id header back', function () {
    $incoming = 'abcdef01-2345-6789-abcd-ef0123456789';

    $response = $this->withHeader('X-Request-Id', $incoming)->getJson('/api/health');

    $response->assertOk();
    expect($response->headers->get('X-Request-Id'))->toBe($incoming);
});

it('records auth events in the activity log', function () {
    $user = User::create([
        'name' => 'Audit',
        'email' => 'audit@example.com',
        'password' => 'secret123',
    ]);
    $user->assignRole('user');

    $this->postJson('/api/login', [
        'email' => 'audit@example.com',
        'password' => 'WRONG',
    ])->assertStatus(422);

    $this->postJson('/api/login', [
        'email' => 'audit@example.com',
        'password' => 'secret123',
    ])->assertOk();

    $events = Activity::where('log_name', 'auth')
        ->orderBy('id')
        ->pluck('event')
        ->all();

    expect($events)->toContain('login_failed', 'login_success');
});

it('throttles /login after 5 attempts per IP', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/login', [
            'email' => "spammer{$i}@example.com",
            'password' => 'whatever',
        ]);
    }

    $this->postJson('/api/login', [
        'email' => 'spammer-final@example.com',
        'password' => 'whatever',
    ])->assertStatus(429);
});

it('locks an email after 5 failed attempts even with rotating IPs', function () {
    $victim = User::create([
        'name' => 'Victim',
        'email' => 'victim@example.com',
        'password' => 'oldsecret123',
    ]);
    $victim->assignRole('user');

    // Drive the email-keyed limit while resetting the IP bucket between
    // attempts (simulating a botnet across many source IPs all hitting
    // one account). Each attempt counts toward BOTH the IP limit and
    // the per-email limit; clearing the IP bucket each round leaves
    // only the email bucket accumulating.
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/login', [
            'email' => 'victim@example.com',
            'password' => 'WRONG',
        ])->assertStatus(422);
        \Illuminate\Support\Facades\RateLimiter::clear('ip:127.0.0.1');
    }

    // 6th attempt — IP bucket is fresh, email bucket is full. Even with
    // the *correct* password, the email is locked.
    $this->postJson('/api/login', [
        'email' => 'victim@example.com',
        'password' => 'oldsecret123',
    ])->assertStatus(429);
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

it('updates the authenticated user\'s profile fields', function () {
    $user = User::create([
        'name' => 'Old Name',
        'email' => 'who@example.com',
        'password' => 'secret123',
        'sex' => 'male',
        'age' => 30,
    ]);
    $user->assignRole('user');

    Sanctum::actingAs($user);

    $response = $this->patchJson('/api/user', [
        'name' => 'New Name',
        'sex' => 'female',
        'age' => 35,
    ]);

    $response->assertOk()
        ->assertJsonPath('user.name', 'New Name')
        ->assertJsonPath('user.sex', 'female')
        ->assertJsonPath('user.age', 35);

    expect($user->fresh()->name)->toBe('New Name');
});

it('rejects profile update with an invalid sex value', function () {
    $user = User::create([
        'name' => 'X',
        'email' => 'x@example.com',
        'password' => 'secret123',
    ]);
    $user->assignRole('user');

    Sanctum::actingAs($user);

    $this->patchJson('/api/user', ['sex' => 'whatever'])->assertStatus(422);
});

it('changes the password when the current one is correct', function () {
    $user = User::create([
        'name' => 'Pwd Changer',
        'email' => 'pwd@example.com',
        'password' => 'oldsecret123',
    ]);
    $user->assignRole('user');

    Sanctum::actingAs($user);

    $response = $this->putJson('/api/user/password', [
        'current_password' => 'oldsecret123',
        'password' => 'N3w!Pass#2026',
        'password_confirmation' => 'N3w!Pass#2026',
    ]);

    $response->assertOk();

    expect(\Illuminate\Support\Facades\Hash::check('N3w!Pass#2026', $user->fresh()->password))->toBeTrue();
});

it('rejects password change with the wrong current password', function () {
    $user = User::create([
        'name' => 'Pwd Failure',
        'email' => 'pwdfail@example.com',
        'password' => 'oldsecret123',
    ]);
    $user->assignRole('user');

    Sanctum::actingAs($user);

    $this->putJson('/api/user/password', [
        'current_password' => 'WRONG',
        'password' => 'newsecret456',
        'password_confirmation' => 'newsecret456',
    ])->assertStatus(422);

    // password unchanged
    expect(\Illuminate\Support\Facades\Hash::check('oldsecret123', $user->fresh()->password))->toBeTrue();
});

it('rejects password change with a weak new password', function () {
    $user = User::create([
        'name' => 'Pwd Weak',
        'email' => 'pwdweak@example.com',
        'password' => 'oldsecret123',
    ]);
    $user->assignRole('user');

    Sanctum::actingAs($user);

    $this->putJson('/api/user/password', [
        'current_password' => 'oldsecret123',
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertStatus(422);
});

it('deletes the account, cascades chats/anamneses/health, revokes tokens', function () {
    $user = User::create([
        'name' => 'Bye',
        'email' => 'bye@example.com',
        'password' => 'oldsecret123',
    ]);
    $user->assignRole('user');

    $chat = \App\Modules\Chat\Models\Chat::create(['user_id' => $user->id, 'title' => 'gone']);
    \App\Modules\Chat\Models\ChatMessage::create([
        'chat_id' => $chat->id, 'role' => 'user', 'message' => 'goodbye',
    ]);
    \App\Modules\Anamnesis\Models\Anamnesis::create([
        'user_id' => $user->id, 'chat_id' => $chat->id,
        'chief_complaint' => 'gone too', 'generated_at' => now(),
    ]);
    \App\Modules\Health\Models\HealthMetric::create([
        'user_id' => $user->id, 'type' => 'steps', 'value' => 1000,
        'unit' => 'count', 'recorded_at' => now(), 'recorded_on' => now()->toDateString(),
    ]);

    Sanctum::actingAs($user);

    $this->deleteJson('/api/user', ['current_password' => 'oldsecret123'])
        ->assertOk()
        ->assertJsonPath('message', 'Account deleted');

    expect(User::find($user->id))->toBeNull()
        ->and(\App\Modules\Chat\Models\Chat::where('user_id', $user->id)->count())->toBe(0)
        ->and(\App\Modules\Chat\Models\ChatMessage::where('chat_id', $chat->id)->count())->toBe(0)
        ->and(\App\Modules\Anamnesis\Models\Anamnesis::where('user_id', $user->id)->count())->toBe(0)
        ->and(\App\Modules\Health\Models\HealthMetric::where('user_id', $user->id)->count())->toBe(0)
        ->and(\Laravel\Sanctum\PersonalAccessToken::where('tokenable_id', $user->id)->count())->toBe(0);
});

it('rejects account deletion with wrong current password', function () {
    $user = User::create([
        'name' => 'Survivor',
        'email' => 'survivor@example.com',
        'password' => 'oldsecret123',
    ]);
    $user->assignRole('user');
    Sanctum::actingAs($user);

    $this->deleteJson('/api/user', ['current_password' => 'WRONG'])->assertStatus(422);
    expect(User::find($user->id))->not->toBeNull();
});

it('rejects unauthenticated account deletion', function () {
    $this->deleteJson('/api/user', ['current_password' => 'whatever'])->assertStatus(401);
});

it('sends a verification notification on register and verifies via signed URL', function () {
    \Illuminate\Support\Facades\Notification::fake();

    $this->postJson('/api/register', [
        'name' => 'Vera',
        'email' => 'vera@example.com',
        'password' => 'T3st!Pass#2026',
        'password_confirmation' => 'T3st!Pass#2026',
    ])->assertCreated();

    $user = User::byEmail('vera@example.com');
    expect($user->hasVerifiedEmail())->toBeFalse();

    \Illuminate\Support\Facades\Notification::assertSentTo($user, \Illuminate\Auth\Notifications\VerifyEmail::class);

    // Build the same signed URL the notification would have generated.
    $signed = \Illuminate\Support\Facades\URL::temporarySignedRoute(
        'verification.verify',
        now()->addHour(),
        ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
    );
    $path = parse_url($signed, PHP_URL_PATH).'?'.parse_url($signed, PHP_URL_QUERY);

    $this->getJson($path)->assertOk()->assertJsonPath('message', 'Email verified');
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('rejects email verification with a tampered hash', function () {
    $user = User::create([
        'name' => 'V', 'email' => 'tamper@example.com', 'password' => 'oldsecret123',
    ]);
    $user->assignRole('user');

    $signed = \Illuminate\Support\Facades\URL::temporarySignedRoute(
        'verification.verify',
        now()->addHour(),
        ['id' => $user->id, 'hash' => sha1('different@example.com')]
    );
    $path = parse_url($signed, PHP_URL_PATH).'?'.parse_url($signed, PHP_URL_QUERY);

    $this->getJson($path)->assertStatus(403);
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('rejects email verification with an unsigned URL', function () {
    $user = User::create([
        'name' => 'V', 'email' => 'unsigned@example.com', 'password' => 'oldsecret123',
    ]);
    $user->assignRole('user');

    $this->getJson("/api/email/verify/{$user->id}/".sha1($user->email))->assertStatus(403);
});

it('forgot-password creates a reset token without leaking which email exists', function () {
    \Illuminate\Support\Facades\Notification::fake();

    $user = User::create([
        'name' => 'Forget', 'email' => 'forget@example.com', 'password' => 'oldsecret123',
    ]);
    $user->assignRole('user');

    $this->postJson('/api/forgot-password', ['email' => 'forget@example.com'])
        ->assertOk()
        ->assertJsonPath('message', 'If an account exists for that email, a reset link has been sent.');

    \Illuminate\Support\Facades\Notification::assertSentTo($user, \Illuminate\Auth\Notifications\ResetPassword::class);

    // Same response for nonexistent email — no enumeration.
    $this->postJson('/api/forgot-password', ['email' => 'nobody@nowhere.local'])
        ->assertOk()
        ->assertJsonPath('message', 'If an account exists for that email, a reset link has been sent.');
});

it('reset-password accepts a valid token and rotates the password + tokens', function () {
    $user = User::create([
        'name' => 'Reset', 'email' => 'reset@example.com', 'password' => 'oldsecret123',
    ]);
    $user->assignRole('user');
    // Existing token rows that the reset must wipe.
    $user->createToken('old');

    $token = \Illuminate\Support\Facades\Password::broker()->getRepository()->create($user);

    $this->postJson('/api/reset-password', [
        'email' => 'reset@example.com',
        'token' => $token,
        'password' => 'N3w!Pass#2026',
        'password_confirmation' => 'N3w!Pass#2026',
    ])->assertOk();

    expect(\Illuminate\Support\Facades\Hash::check('N3w!Pass#2026', $user->fresh()->password))->toBeTrue()
        ->and(\Laravel\Sanctum\PersonalAccessToken::where('tokenable_id', $user->id)->count())->toBe(0);
});

it('reset-password rejects invalid token', function () {
    $user = User::create([
        'name' => 'Bad', 'email' => 'badtok@example.com', 'password' => 'oldsecret123',
    ]);
    $user->assignRole('user');

    $this->postJson('/api/reset-password', [
        'email' => 'badtok@example.com',
        'token' => 'definitely-not-a-valid-token',
        'password' => 'N3w!Pass#2026',
        'password_confirmation' => 'N3w!Pass#2026',
    ])->assertStatus(422);

    expect(\Illuminate\Support\Facades\Hash::check('oldsecret123', $user->fresh()->password))->toBeTrue();
});

it('reset-password rejects nonexistent email with same shape (no enumeration)', function () {
    $this->postJson('/api/reset-password', [
        'email' => 'ghost@nowhere.local',
        'token' => 'whatever',
        'password' => 'N3w!Pass#2026',
        'password_confirmation' => 'N3w!Pass#2026',
    ])->assertStatus(422);
});
