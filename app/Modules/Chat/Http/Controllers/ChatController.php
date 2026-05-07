<?php

namespace App\Modules\Chat\Http\Controllers;

use App\Modules\AI\Services\AIService;
use App\Modules\Chat\Events\AssistantReplyCreated;
use App\Modules\Chat\Events\MessageSent;
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
            $driver = DB::connection()->getDriverName();
            $op = $driver === 'pgsql' ? 'ilike' : 'like';
            $query->where('title', $op, "%{$q}%");
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

                // First turn (no conversation_id yet): send history so the
                // server can prime its memory. Subsequent turns: omit history
                // and let the server reconstruct from its own Redis copy.
                $history = $chat->conversation_id
                    ? null
                    : $this->buildHistory($chat, exclude: $userMessage->id);
                $aiResponse = $this->ai->chat(
                    $validated['message'],
                    $history,
                    $chat->user,
                    $locale,
                    $chat->conversation_id,
                );

                $this->captureConversationId($chat, $aiResponse);

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

            MessageSent::dispatch($userMessage);
            AssistantReplyCreated::dispatch($assistantMessage);

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
                'error' => [
                    'code' => 'AI_UPSTREAM_FAILED',
                    'message' => 'Failed to generate AI response',
                ],
            ], 502);
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

        // First turn (no conversation_id yet): send history. Otherwise let
        // the server reconstruct from its own Redis copy.
        $history = $chat->conversation_id
            ? null
            : $this->buildHistory($chat, exclude: [$userMessage->id, $assistantMessage->id]);
        $user = $chat->user;
        $ai = $this->ai;
        $conversationId = $chat->conversation_id;

        return new StreamedResponse(function () use ($chat, $userMessage, $assistantMessage, $validated, $history, $user, $ai, $locale, $conversationId) {
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
            $serverConversationId = null;
            try {
                foreach ($ai->streamChat($validated['message'], $history, $user, $locale, $conversationId) as $chunk) {
                    $sendEvent($chunk['event'], $chunk['data']);

                    if ($chunk['event'] === 'delta' && is_array($chunk['data']) && isset($chunk['data']['text'])) {
                        $fullText .= $chunk['data']['text'];
                    }
                    if ($chunk['event'] === 'final' && is_array($chunk['data']) && isset($chunk['data']['answer'])) {
                        $fullText = $chunk['data']['answer'];
                    }
                    // Server emits conversation_id in `meta` (always first)
                    // and `final`; keep the most recent one we see.
                    if (in_array($chunk['event'], ['meta', 'final', 'error'], true)
                        && is_array($chunk['data'])
                        && !empty($chunk['data']['conversation_id'])) {
                        $serverConversationId = $chunk['data']['conversation_id'];
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

            if ($serverConversationId) {
                $this->captureConversationId($chat, ['conversation_id' => $serverConversationId]);
            }

            $chat->touch();

            if (!$streamErrored) {
                MessageSent::dispatch($userMessage);
                AssistantReplyCreated::dispatch($assistantMessage);
            }

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

                // Regenerate mutates server-side memory: the previous
                // assistant turn is gone from our DB so we must override
                // the server's Redis copy by sending fresh history alongside
                // conversation_id.
                $history = $this->buildHistory($chat, exclude: $lastUser->id);
                $aiResponse = $this->ai->chat(
                    $lastUser->message,
                    $history,
                    $chat->user,
                    $locale,
                    $chat->conversation_id,
                );

                $this->captureConversationId($chat, $aiResponse);

                $msg = ChatMessage::create([
                    'chat_id' => $chat->id,
                    'role' => 'assistant',
                    'message' => $aiResponse['answer'] ?? 'Unable to generate response',
                    'metadata' => ['source' => 'ai_module', 'regenerated' => true],
                ]);

                $chat->touch();

                return $msg;
            });

            AssistantReplyCreated::dispatch($assistantMessage);

            return response()->json([
                'assistant_message' => $this->formatMessage($assistantMessage),
            ]);
        } catch (\Throwable $e) {
            Log::error('Regenerate error: '.$e->getMessage(), [
                'chat_id' => $chat->id,
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'error' => [
                    'code' => 'AI_UPSTREAM_FAILED',
                    'message' => 'Failed to regenerate AI response',
                ],
            ], 502);
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

                // Edit truncates history at the edit point, so we resend
                // the corrected transcript to override the server's Redis
                // copy. Same pattern as regenerate.
                $history = $this->buildHistory($chat, exclude: $msg->id);
                $aiResponse = $this->ai->chat(
                    $validated['message'],
                    $history,
                    $chat->user,
                    $locale,
                    $chat->conversation_id,
                );

                $this->captureConversationId($chat, $aiResponse);

                $assistantMessage = ChatMessage::create([
                    'chat_id' => $chat->id,
                    'role' => 'assistant',
                    'message' => $aiResponse['answer'] ?? 'Unable to generate response',
                    'metadata' => ['source' => 'ai_module', 'edited' => true],
                ]);

                $chat->touch();

                return [$msg, $assistantMessage];
            });

            MessageSent::dispatch($msg);
            AssistantReplyCreated::dispatch($assistantMessage);

            return response()->json([
                'user_message' => $this->formatMessage($msg),
                'assistant_message' => $this->formatMessage($assistantMessage),
            ]);
        } catch (\Throwable $e) {
            Log::error('Edit message error: '.$e->getMessage(), [
                'chat_id' => $chat->id,
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'error' => [
                    'code' => 'AI_UPSTREAM_FAILED',
                    'message' => 'Failed to regenerate AI response after edit',
                ],
            ], 502);
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

    private function captureConversationId(Chat $chat, array $aiResponse): void
    {
        $cid = $aiResponse['conversation_id'] ?? null;
        if ($cid && $cid !== $chat->conversation_id) {
            $chat->update(['conversation_id' => $cid]);
        }
    }

    private const HISTORY_TURN_CAP = 20;

    private function buildHistory(Chat $chat, int|array|null $exclude = null): array
    {
        $query = ChatMessage::where('chat_id', $chat->id)
            ->where('status', '!=', 'pending');

        if ($exclude !== null) {
            $query->whereNotIn('id', (array) $exclude);
        }

        $latest = $query->orderBy('created_at', 'desc')
            ->limit(self::HISTORY_TURN_CAP)
            ->get()
            ->reverse()
            ->values();

        return $latest->map(fn ($m) => [
            'role' => $m->role,
            'content' => $m->message,
        ])->all();
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
