<?php

namespace App\Modules\Chat\Http\Controllers;

use App\Modules\AI\Services\AIService;
use App\Modules\Chat\Models\Chat;
use App\Modules\Chat\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function __construct(private AIService $ai) {}

    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 20);
        $q = $request->query('q');

        $query = Chat::where('user_id', Auth::id())
            ->with(['messages' => fn ($m) => $m->latest()->limit(1)])
            ->orderBy('updated_at', 'desc');

        if ($q) {
            $query->where(function ($w) use ($q) {
                $w->where('title', 'like', "%{$q}%")
                    ->orWhereHas('messages', fn ($m) => $m->where('message', 'like', "%{$q}%"));
            });
        }

        $paginated = $query->paginate($perPage);

        $paginated->getCollection()->transform(fn ($chat) => [
            'id' => $chat->id,
            'title' => $chat->title ?? 'Untitled Chat',
            'last_message' => $chat->messages->first()?->message,
            'created_at' => $chat->created_at,
            'updated_at' => $chat->updated_at,
        ]);

        return response()->json($paginated);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
        ]);

        $chat = Auth::user()->chats()->create([
            'title' => $validated['title'] ?? null,
        ]);

        return response()->json([
            'id' => $chat->id,
            'title' => $chat->title,
            'messages' => [],
            'created_at' => $chat->created_at,
        ], 201);
    }

    public function show(Request $request, string $id)
    {
        $chat = Chat::where('user_id', Auth::id())->findOrFail($id);
        $perPage = (int) $request->query('per_page', 50);

        $messages = $chat->messages()->orderBy('created_at', 'asc')->paginate($perPage);

        $messages->getCollection()->transform(fn ($msg) => [
            'id' => $msg->id,
            'role' => $msg->role,
            'message' => $msg->message,
            'created_at' => $msg->created_at,
        ]);

        return response()->json([
            'id' => $chat->id,
            'title' => $chat->title,
            'created_at' => $chat->created_at,
            'updated_at' => $chat->updated_at,
            'messages' => $messages,
        ]);
    }

    public function sendMessage(Request $request, string $id)
    {
        $chat = Chat::where('user_id', Auth::id())->findOrFail($id);

        $validated = $request->validate([
            'message' => 'required|string|max:4000',
            'locale' => 'nullable|string|max:10',
        ]);

        $locale = $this->resolveLocale($request, $validated['locale'] ?? null);

        try {
            [$userMessage, $assistantMessage] = DB::transaction(function () use ($chat, $validated, $locale) {
                $userMessage = ChatMessage::create([
                    'chat_id' => $chat->id,
                    'role' => 'user',
                    'message' => $validated['message'],
                ]);

                $this->autoTitle($chat, $validated['message']);

                $history = $this->buildHistory($chat, exclude: $userMessage->id);
                $aiResponse = $this->ai->chat($validated['message'], $history, $chat->user, $locale);

                $assistantMessage = ChatMessage::create([
                    'chat_id' => $chat->id,
                    'role' => 'assistant',
                    'message' => $aiResponse['answer'] ?? 'Unable to generate response',
                    'metadata' => [
                        'source' => 'ai_module',
                        'rag_used' => $aiResponse['rag_used'] ?? null,
                        'intent' => $aiResponse['intent'] ?? null,
                    ],
                ]);

                $chat->touch();

                return [$userMessage, $assistantMessage];
            });

            return response()->json([
                'user_message' => $this->formatMessage($userMessage),
                'assistant_message' => $this->formatMessage($assistantMessage),
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Chat AI Error: '.$e->getMessage(), [
                'chat_id' => $chat->id,
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'error' => 'Failed to generate AI response',
                'detail' => $e->getMessage(),
            ], 500);
        }
    }

    public function streamMessage(Request $request, string $id): StreamedResponse
    {
        $chat = Chat::where('user_id', Auth::id())->findOrFail($id);

        $validated = $request->validate([
            'message' => 'required|string|max:4000',
            'locale' => 'nullable|string|max:10',
        ]);

        $locale = $this->resolveLocale($request, $validated['locale'] ?? null);

        // Pre-stream setup: create user msg + pending assistant placeholder.
        // Wrapped in a transaction so a DB failure here doesn't leave half-state.
        // Once we start flushing the SSE response we can no longer rollback,
        // so the post-stream update path uses status='partial' on errors.
        [$userMessage, $assistantMessage] = DB::transaction(function () use ($chat, $validated) {
            $userMessage = ChatMessage::create([
                'chat_id' => $chat->id,
                'role' => 'user',
                'message' => $validated['message'],
            ]);

            $this->autoTitle($chat, $validated['message']);

            $assistantMessage = ChatMessage::create([
                'chat_id' => $chat->id,
                'role' => 'assistant',
                'message' => '',
                'status' => 'pending',
                'metadata' => ['source' => 'ai_module_stream'],
            ]);

            return [$userMessage, $assistantMessage];
        });

        $history = $this->buildHistory($chat, exclude: [$userMessage->id, $assistantMessage->id]);
        $user = $chat->user;
        $ai = $this->ai;

        return new StreamedResponse(function () use ($chat, $userMessage, $assistantMessage, $validated, $history, $user, $ai, $locale) {
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', '0');
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $sendEvent = function (string $event, $data) {
                echo "event: {$event}\n";
                echo 'data: '.(is_string($data) ? $data : json_encode($data))."\n\n";
                @ob_flush();
                @flush();
            };

            $sendEvent('meta', [
                'chat_id' => $chat->id,
                'user_message_id' => $userMessage->id,
                'assistant_message_id' => $assistantMessage->id,
            ]);

            $fullText = '';
            $streamErrored = false;
            try {
                foreach ($ai->streamChat($validated['message'], $history, $user, $locale) as $chunk) {
                    $sendEvent($chunk['event'], $chunk['data']);

                    if ($chunk['event'] === 'delta' && is_array($chunk['data']) && isset($chunk['data']['text'])) {
                        $fullText .= $chunk['data']['text'];
                    }
                    if ($chunk['event'] === 'final' && is_array($chunk['data']) && isset($chunk['data']['answer'])) {
                        $fullText = $chunk['data']['answer'];
                    }
                    if ($chunk['event'] === 'error') {
                        $streamErrored = true;
                    }
                }
            } catch (\Throwable $e) {
                Log::error('streamMessage error: '.$e->getMessage(), [
                    'chat_id' => $chat->id,
                    'user_id' => $user->id,
                ]);
                $streamErrored = true;
                $sendEvent('error', ['message' => 'Stream interrupted']);
            }

            $assistantMessage->update([
                'message' => $fullText !== '' ? $fullText : 'Unable to generate response',
                'status' => $streamErrored ? 'partial' : 'complete',
            ]);

            $chat->touch();

            $sendEvent('saved', [
                'assistant_message_id' => $assistantMessage->id,
                'status' => $assistantMessage->status,
            ]);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    public function regenerate(Request $request, string $id)
    {
        $chat = Chat::where('user_id', Auth::id())->findOrFail($id);

        $request->validate(['locale' => 'nullable|string|max:10']);
        $locale = $this->resolveLocale($request, $request->input('locale'));

        $lastUser = ChatMessage::where('chat_id', $chat->id)
            ->where('role', 'user')
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$lastUser) {
            return response()->json(['error' => 'No user message to regenerate from'], 422);
        }

        try {
            $assistantMessage = DB::transaction(function () use ($chat, $lastUser, $locale) {
                ChatMessage::where('chat_id', $chat->id)
                    ->where('role', 'assistant')
                    ->orderBy('created_at', 'desc')
                    ->limit(1)
                    ->delete();

                $history = $this->buildHistory($chat, exclude: $lastUser->id);
                $aiResponse = $this->ai->chat($lastUser->message, $history, $chat->user, $locale);

                $msg = ChatMessage::create([
                    'chat_id' => $chat->id,
                    'role' => 'assistant',
                    'message' => $aiResponse['answer'] ?? 'Unable to generate response',
                    'metadata' => ['source' => 'ai_module', 'regenerated' => true],
                ]);

                $chat->touch();

                return $msg;
            });

            return response()->json([
                'assistant_message' => $this->formatMessage($assistantMessage),
            ]);
        } catch (\Throwable $e) {
            Log::error('Regenerate error: '.$e->getMessage());

            return response()->json(['error' => 'Failed to regenerate', 'detail' => $e->getMessage()], 500);
        }
    }

    public function updateMessage(Request $request, string $id, string $messageId)
    {
        $chat = Chat::where('user_id', Auth::id())->findOrFail($id);

        $msg = ChatMessage::where('chat_id', $chat->id)->findOrFail($messageId);

        $validated = $request->validate([
            'message' => 'required|string|max:4000',
            'locale' => 'nullable|string|max:10',
        ]);

        $locale = $this->resolveLocale($request, $validated['locale'] ?? null);

        if ($msg->role !== 'user') {
            return response()->json(['error' => 'Only user messages can be edited'], 422);
        }

        try {
            [$msg, $assistantMessage] = DB::transaction(function () use ($chat, $msg, $validated, $locale) {
                $msg->update(['message' => $validated['message']]);

                ChatMessage::where('chat_id', $chat->id)
                    ->where('created_at', '>', $msg->created_at)
                    ->delete();

                $history = $this->buildHistory($chat, exclude: $msg->id);
                $aiResponse = $this->ai->chat($validated['message'], $history, $chat->user, $locale);

                $assistantMessage = ChatMessage::create([
                    'chat_id' => $chat->id,
                    'role' => 'assistant',
                    'message' => $aiResponse['answer'] ?? 'Unable to generate response',
                    'metadata' => ['source' => 'ai_module', 'edited' => true],
                ]);

                $chat->touch();

                return [$msg, $assistantMessage];
            });

            return response()->json([
                'user_message' => $this->formatMessage($msg),
                'assistant_message' => $this->formatMessage($assistantMessage),
            ]);
        } catch (\Throwable $e) {
            Log::error('Edit message error: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to regenerate AI response',
                'detail' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteMessage(string $id, string $messageId)
    {
        $chat = Chat::where('user_id', Auth::id())->findOrFail($id);
        $msg = ChatMessage::where('chat_id', $chat->id)->findOrFail($messageId);
        $msg->delete();
        $chat->touch();

        return response()->json(['message' => 'Message deleted', 'id' => $messageId]);
    }

    public function update(Request $request, string $id)
    {
        $chat = Chat::where('user_id', Auth::id())->findOrFail($id);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
        ]);

        $chat->update(['title' => $validated['title']]);

        return response()->json([
            'id' => $chat->id,
            'title' => $chat->title,
            'updated_at' => $chat->updated_at,
        ]);
    }

    public function destroy(string $id)
    {
        $chat = Chat::where('user_id', Auth::id())->findOrFail($id);
        $chat->delete();

        return response()->json(['message' => 'Chat deleted', 'id' => $id]);
    }

    private function resolveLocale(Request $request, ?string $bodyLocale): string
    {
        if ($bodyLocale) {
            return $bodyLocale;
        }
        $header = $request->header('Accept-Language');
        if ($header) {
            return explode(',', $header)[0];
        }

        return 'en';
    }

    private function buildHistory(Chat $chat, int|array|null $exclude = null): array
    {
        $query = ChatMessage::where('chat_id', $chat->id)
            ->where('status', '!=', 'pending')
            ->orderBy('created_at', 'asc');

        if ($exclude !== null) {
            $query->whereNotIn('id', (array) $exclude);
        }

        return $query->get()->map(fn ($m) => [
            'role' => $m->role,
            'content' => $m->message,
        ])->values()->all();
    }

    private function autoTitle(Chat $chat, string $firstMessage): void
    {
        if (!$chat->title && $chat->messages()->count() === 1) {
            $chat->update(['title' => mb_substr($firstMessage, 0, 50)]);
        }
    }

    private function formatMessage(ChatMessage $msg): array
    {
        return [
            'id' => $msg->id,
            'role' => $msg->role,
            'message' => $msg->message,
            'created_at' => $msg->created_at,
        ];
    }
}
