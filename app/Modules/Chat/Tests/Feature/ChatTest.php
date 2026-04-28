<?php

use App\Modules\AI\Services\AIService;
use App\Modules\Auth\Models\User;
use App\Modules\Chat\Models\Chat;
use App\Modules\Chat\Models\ChatMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    seedPermissions();

    $this->user = User::create([
        'name' => 'Frank',
        'email' => 'frank@example.com',
        'password' => 'secret123',
    ]);
    $this->user->assignRole('user');

    Sanctum::actingAs($this->user);
});

it('creates a chat for the authenticated user', function () {
    $response = $this->postJson('/api/chats', ['title' => 'My first chat']);

    $response->assertCreated()
        ->assertJsonPath('title', 'My first chat');

    expect(Chat::where('user_id', $this->user->id)->count())->toBe(1);
});

it('sends a message, calls AIService, and persists user + assistant rows', function () {
    $this->mock(AIService::class, function ($mock) {
        $mock->shouldReceive('chat')
            ->once()
            ->andReturn(['answer' => 'Hello from mocked AI', 'rag_used' => false]);
    });

    $chat = Chat::create(['user_id' => $this->user->id, 'title' => null]);

    $response = $this->postJson("/api/chats/{$chat->id}/messages", [
        'message' => 'I have a headache',
        'locale' => 'en',
    ]);

    $response->assertCreated()
        ->assertJsonPath('user_message.message', 'I have a headache')
        ->assertJsonPath('assistant_message.message', 'Hello from mocked AI');

    expect(ChatMessage::where('chat_id', $chat->id)->count())->toBe(2);
});

it('returns the chat with paginated messages', function () {
    $chat = Chat::create(['user_id' => $this->user->id, 'title' => 'History']);
    ChatMessage::create(['chat_id' => $chat->id, 'role' => 'user', 'message' => 'hi']);
    ChatMessage::create(['chat_id' => $chat->id, 'role' => 'assistant', 'message' => 'hello']);

    $response = $this->getJson("/api/chats/{$chat->id}");

    $response->assertOk()
        ->assertJsonPath('id', $chat->id)
        ->assertJsonPath('messages.data.0.message', 'hi')
        ->assertJsonPath('messages.data.1.message', 'hello');
});

it('rolls back the user message when the AI call fails', function () {
    $this->mock(AIService::class, function ($mock) {
        $mock->shouldReceive('chat')
            ->once()
            ->andThrow(new \RuntimeException('AI service unavailable'));
    });

    $chat = Chat::create(['user_id' => $this->user->id, 'title' => null]);

    $response = $this->postJson("/api/chats/{$chat->id}/messages", [
        'message' => 'I have a headache',
    ]);

    $response->assertStatus(500);

    expect(ChatMessage::where('chat_id', $chat->id)->count())->toBe(0);
});

it('replays the cached response when the same Idempotency-Key is used twice', function () {
    $this->mock(AIService::class, function ($mock) {
        $mock->shouldReceive('chat')
            ->once()
            ->andReturn(['answer' => 'first reply']);
    });

    $chat = Chat::create(['user_id' => $this->user->id, 'title' => null]);
    $key = 'a1b2c3d4-e5f6-7890-abcd-ef0123456789';

    $first = $this->withHeader('Idempotency-Key', $key)
        ->postJson("/api/chats/{$chat->id}/messages", ['message' => 'hi']);

    $second = $this->withHeader('Idempotency-Key', $key)
        ->postJson("/api/chats/{$chat->id}/messages", ['message' => 'hi']);

    $first->assertCreated();
    $second->assertCreated()->assertHeader('Idempotent-Replay', 'true');

    expect($first->json('assistant_message.id'))->toBe($second->json('assistant_message.id'))
        ->and(ChatMessage::where('chat_id', $chat->id)->count())->toBe(2);
});

it('rejects a non-UUID Idempotency-Key with 400', function () {
    $chat = Chat::create(['user_id' => $this->user->id, 'title' => null]);

    $this->withHeader('Idempotency-Key', 'not-a-uuid')
        ->postJson("/api/chats/{$chat->id}/messages", ['message' => 'hi'])
        ->assertStatus(400);
});

it("does not allow a user to read another user's chat", function () {
    $other = User::create([
        'name' => 'Mallory',
        'email' => 'mallory@example.com',
        'password' => 'secret123',
    ]);
    $other->assignRole('user');
    $otherChat = Chat::create(['user_id' => $other->id, 'title' => 'private']);

    $this->getJson("/api/chats/{$otherChat->id}")->assertNotFound();
});
