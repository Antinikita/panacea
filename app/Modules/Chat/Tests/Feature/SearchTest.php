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

    // Populate embeddings deterministically using the mock Embedder.
    $embedder = new Embedder;
    foreach ([$headache, $shoes] as $msg) {
        $vec = $embedder->embed($msg->message);
        $literal = '['.implode(',', $vec).']';
        DB::update('UPDATE chat_messages SET embedding = ?::vector WHERE id = ?', [$literal, $msg->id]);
    }

    config(['app.env' => 'testing']);
    putenv('AI_USE_MOCK=true');

    $response = $this->getJson('/api/search?q=splitting%20headache&mode=semantic&limit=1');

    $response->assertOk()
        ->assertJsonPath('mode', 'semantic')
        ->assertJsonPath('count', 1)
        ->assertJsonPath('results.0.message_id', $headache->id);
});
