<?php

use App\Modules\Auth\Models\User;
use App\Modules\Chat\Models\Chat;
use App\Modules\Chat\Models\ChatMessage;
use App\Modules\AI\Services\AIService;
use Laravel\Sanctum\Sanctum;

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
