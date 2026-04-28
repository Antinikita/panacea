<?php

use App\Modules\Anamnesis\Models\Anamnesis;
use App\Modules\Auth\Models\User;
use App\Modules\Chat\Models\Chat;
use App\Modules\Chat\Models\ChatMessage;
use App\Modules\AI\Services\AIService;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    seedPermissions();

    $this->user = User::create([
        'name' => 'Grace',
        'email' => 'grace@example.com',
        'password' => 'secret123',
    ]);
    $this->user->assignRole('user');

    Sanctum::actingAs($this->user);
});

it('generates an anamnesis from a chat by parsing the AI JSON answer', function () {
    $this->mock(AIService::class, function ($mock) {
        $mock->shouldReceive('chat')
            ->once()
            ->andReturn([
                'answer' => json_encode([
                    'chief_complaint' => 'headache for 3 days',
                    'history_present_illness' => 'started after work stress',
                    'past_medical_history' => null,
                    'family_history' => null,
                    'social_history' => null,
                    'allergies' => null,
                    'medications' => null,
                    'review_of_systems' => null,
                ]),
            ]);
    });

    $chat = Chat::create(['user_id' => $this->user->id, 'title' => 'Headache']);
    ChatMessage::create(['chat_id' => $chat->id, 'role' => 'user', 'message' => 'I have had a headache for 3 days.']);
    ChatMessage::create(['chat_id' => $chat->id, 'role' => 'assistant', 'message' => 'Tell me more about it.']);

    $response = $this->postJson("/api/chats/{$chat->id}/anamnesis", ['locale' => 'en']);

    $response->assertCreated()
        ->assertJsonPath('parsed_successfully', true)
        ->assertJsonPath('anamnesis.chief_complaint', 'headache for 3 days');

    expect(Anamnesis::where('user_id', $this->user->id)->count())->toBe(1);
});

it('rejects anamnesis generation on a chat with no messages', function () {
    $chat = Chat::create(['user_id' => $this->user->id, 'title' => 'Empty']);

    $this->postJson("/api/chats/{$chat->id}/anamnesis")->assertStatus(422);
});
