<?php

namespace App\Modules\Anamnesis\Http\Controllers;

use App\Modules\AI\Services\AIService;
use App\Modules\Anamnesis\Models\Anamnesis;
use App\Modules\Chat\Models\Chat;
use App\Modules\Chat\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AnamnesisController extends Controller
{
    private const FIELDS = [
        'chief_complaint',
        'history_present_illness',
        'past_medical_history',
        'family_history',
        'social_history',
        'allergies',
        'medications',
        'review_of_systems',
    ];

    public function __construct(private AIService $ai) {}

    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 20);

        $paginated = Anamnesis::where('user_id', Auth::id())
            ->with('chat:id,title')
            ->orderBy('generated_at', 'desc')
            ->paginate($perPage);

        return response()->json($paginated);
    }

    public function show(string $id)
    {
        $anamnesis = Anamnesis::where('user_id', Auth::id())
            ->with('chat:id,title')
            ->findOrFail($id);

        return response()->json($anamnesis);
    }

    public function update(Request $request, string $id)
    {
        $anamnesis = Anamnesis::where('user_id', Auth::id())->findOrFail($id);

        $rules = [];
        foreach (self::FIELDS as $field) {
            $rules[$field] = 'sometimes|nullable|string';
        }
        $validated = $request->validate($rules);

        $anamnesis->update($validated);

        return response()->json($anamnesis->fresh());
    }

    public function destroy(string $id)
    {
        $anamnesis = Anamnesis::where('user_id', Auth::id())->findOrFail($id);
        $anamnesis->delete();

        return response()->json(['message' => 'Anamnesis deleted', 'id' => $id]);
    }

    public function generateFromChat(Request $request, string $chatId)
    {
        $chat = Chat::where('user_id', Auth::id())->findOrFail($chatId);

        $request->validate(['locale' => 'nullable|string|max:10']);
        $locale = $request->input('locale')
            ?: (explode(',', (string) $request->header('Accept-Language'))[0] ?: 'en');

        $messages = ChatMessage::where('chat_id', $chat->id)
            ->orderBy('created_at', 'asc')
            ->get();

        if ($messages->isEmpty()) {
            return response()->json(['error' => 'Chat has no messages to summarize'], 422);
        }

        $history = $messages->map(fn ($m) => [
            'role' => $m->role,
            'content' => $m->message,
        ])->values()->all();

        $prompt = $this->buildAnamnesisPrompt();

        try {
            $aiResponse = $this->ai->chat($prompt, $history, $chat->user, $locale);
            $answer = $aiResponse['answer'] ?? '';
            $parsed = $this->parseJson($answer);

            $fields = [];
            foreach (self::FIELDS as $field) {
                $fields[$field] = $parsed[$field] ?? null;
            }

            $anamnesis = Anamnesis::create(array_merge($fields, [
                'user_id' => Auth::id(),
                'chat_id' => $chat->id,
                'generated_at' => now(),
            ]));

            // ai_raw_response is set explicitly (not via mass assignment)
            // so a malicious PATCH body can't inject arbitrary metadata.
            $anamnesis->ai_raw_response = [
                'answer' => $answer,
                'meta' => array_intersect_key($aiResponse, array_flip(['rag_used', 'intent', 'disclaimer'])),
            ];
            $anamnesis->save();

            return response()->json([
                'anamnesis' => $anamnesis,
                'parsed_successfully' => $parsed !== null,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Anamnesis generation failed: '.$e->getMessage(), [
                'chat_id' => $chat->id,
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'error' => [
                    'code' => 'AI_UPSTREAM_FAILED',
                    'message' => 'Failed to generate anamnesis',
                ],
            ], 502);
        }
    }

    private function buildAnamnesisPrompt(): string
    {
        $fields = implode(', ', self::FIELDS);

        return 'Based on our conversation so far, produce a structured medical anamnesis. '
            .'Output ONLY a valid JSON object (no prose, no markdown, no code fences) with exactly these keys: '
            .$fields.'. '
            .'Each value must be a concise string summarizing what the patient said in that category, or null if not mentioned. '
            .'Do not invent details. Example output: '
            .'{"chief_complaint":"headache for 3 days","history_present_illness":"...","past_medical_history":null,'
            .'"family_history":null,"social_history":null,"allergies":null,"medications":null,"review_of_systems":null}';
    }

    private function parseJson(string $text): ?array
    {
        $text = trim($text);
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $stripped = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);
        $stripped = trim($stripped);
        $decoded = json_decode($stripped, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
