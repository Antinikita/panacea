<?php

use App\Modules\AI\Services\Embedder;
use App\Modules\Auth\Models\User;
use App\Modules\Chat\Models\Chat;
use App\Modules\Chat\Models\ChatMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    seedPermissions();

    $this->user = User::create([
        'name' => 'Searcher',
        'email' => 'searcher@example.com',
        'password' => 'secret123',
    ]);
    $this->user->assignRole('user');

    Sanctum::actingAs($this->user);
});

it('text-searches the user\'s own messages', function () {
    $chat = Chat::create(['user_id' => $this->user->id, 'title' => 'About head pain']);
    ChatMessage::create([
        'chat_id' => $chat->id,
        'role' => 'user',
        'message' => 'I have a really bad headache',
    ]);
    ChatMessage::create([
        'chat_id' => $chat->id,
        'role' => 'assistant',
        'message' => 'Tell me more about your back pain',
    ]);

    $response = $this->getJson('/api/search?q=headache&mode=text');

    $response->assertOk()
        ->assertJsonPath('mode', 'text');

    $messages = collect($response->json('results'))->pluck('snippet')->all();
    expect($messages)->toContain('I have a really bad headache')
        ->and($messages)->not->toContain('Tell me more about your back pain');
});

it('does not surface another user\'s messages from search', function () {
    $other = User::create([
        'name' => 'Mallory',
        'email' => 'mallory@example.com',
        'password' => 'secret123',
    ]);
    $other->assignRole('user');

    $foreignChat = Chat::create(['user_id' => $other->id, 'title' => 'Private']);
    ChatMessage::create([
        'chat_id' => $foreignChat->id,
        'role' => 'user',
        'message' => 'a clearly unique secret string xyzzy',
    ]);

    $response = $this->getJson('/api/search?q=xyzzy&mode=text');

    $response->assertOk()
        ->assertJsonPath('count', 0);
});

it('rejects search without a query', function () {
    $this->getJson('/api/search?mode=text')->assertStatus(422);
});

it('rejects an unknown mode', function () {
    $this->getJson('/api/search?q=hi&mode=fancy')->assertStatus(422);
});

it('semantic-searches via pgvector cosine distance', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('semantic search requires pgvector (Postgres-only)');
    }

    $chat = Chat::create(['user_id' => $this->user->id, 'title' => null]);

    $headache = ChatMessage::create([
        'chat_id' => $chat->id,
        'role' => 'user',
        'message' => 'I have a splitting headache and dizziness',
    ]);
    $shoes = ChatMessage::create([
        'chat_id' => $chat->id,
        'role' => 'user',
        'message' => 'My new shoes feel comfortable',
    ]);

    // Inject deterministic vectors with real cosine distance: the query
    // and the headache row share an axis; the shoes row is orthogonal.
    // The mock Embedder's SHA256-derived vectors don't preserve meaning,
    // so we can't rely on it to test ranking — that's a test concern,
    // not a production one.
    $queryVec = vectorOnAxis(0);
    $headacheVec = vectorOnAxis(0, weight: 0.9);
    $shoesVec = vectorOnAxis(1);

    DB::update('UPDATE chat_messages SET embedding = ?::vector WHERE id = ?', ['['.implode(',', $headacheVec).']', $headache->id]);
    DB::update('UPDATE chat_messages SET embedding = ?::vector WHERE id = ?', ['['.implode(',', $shoesVec).']', $shoes->id]);

    $this->mock(Embedder::class, function ($mock) use ($queryVec) {
        $mock->shouldReceive('embed')->andReturn($queryVec);
    });

    $response = $this->getJson('/api/search?q=splitting%20headache&mode=semantic&limit=1');

    $response->assertOk()
        ->assertJsonPath('mode', 'semantic')
        ->assertJsonPath('count', 1)
        ->assertJsonPath('results.0.message_id', $headache->id);
});

/**
 * Builds a 1536-dim vector with a 1.0 along $axis and zeros elsewhere
 * (or weight along the axis if specified). Used to construct rows with
 * predictable cosine distance for ranking assertions.
 */
function vectorOnAxis(int $axis, float $weight = 1.0): array
{
    $vec = array_fill(0, 1536, 0.0);
    $vec[$axis] = $weight;

    return $vec;
}
