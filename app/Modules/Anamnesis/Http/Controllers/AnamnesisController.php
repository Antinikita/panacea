<?php

namespace App\Modules\Anamnesis\Http\Controllers;

use App\Modules\AI\Services\AIService;
use App\Modules\Anamnesis\Models\Anamnesis;
use App\Modules\Chat\Models\Chat;
use App\Modules\Chat\Models\ChatMessage;
use App\Modules\Health\Services\HealthQueryService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\App;
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

    private const FIELD_MAX_CHARS = 10000;

    public function update(Request $request, string $id)
    {
        $anamnesis = Anamnesis::where('user_id', Auth::id())->findOrFail($id);

        $rules = [];
        foreach (self::FIELDS as $field) {
            $rules[$field] = 'sometimes|nullable|string|max:'.self::FIELD_MAX_CHARS;
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

    public function download(Request $request, string $id)
    {
        $request->validate(['locale' => 'nullable|string|in:en,ru,kk']);
        $locale = $request->input('locale')
            ?: substr((string) $request->header('Accept-Language'), 0, 2)
            ?: 'en';
        if (! in_array($locale, ['en', 'ru', 'kk'], true)) {
            $locale = 'en';
        }

        $anamnesis = Anamnesis::where('user_id', Auth::id())
            ->with(['chat:id,title', 'user:id,name,email,age,sex'])
            ->findOrFail($id);

        // Localize translation strings for the duration of this render only.
        $original = App::getLocale();
        App::setLocale($locale);

        try {
            $data = $this->buildPdfData($anamnesis, $locale);
            $pdf = Pdf::loadView('anamnesis.pdf', $data)
                ->setPaper('a4', 'portrait')
                ->setOption('defaultFont', 'DejaVu Sans');

            $filename = sprintf('anamnesis-%d-%s.pdf', $anamnesis->id, $locale);

            return $pdf->download($filename);
        } finally {
            App::setLocale($original);
        }
    }

    private function buildPdfData(Anamnesis $anamnesis, string $locale): array
    {
        $user = $anamnesis->user;

        $fields = [];
        foreach (self::FIELDS as $field) {
            $fields[$field] = $anamnesis->{$field};
        }

        $generatedAt = ($anamnesis->generated_at ?? $anamnesis->created_at)?->locale($locale)
            ->isoFormat('D MMMM YYYY, HH:mm');

        return [
            'locale' => $locale,
            'patient' => [
                'name' => $user?->name,
                'email' => $user?->email,
                'age' => $user?->age,
                'sex' => $user?->sex,
            ],
            'chatTitle' => $anamnesis->chat?->title,
            'generatedAt' => $generatedAt ?? '—',
            'fields' => $fields,
            'healthRows' => $this->buildHealthRows($anamnesis->health_context, $locale),
        ];
    }

    private function buildHealthRows(?array $ctx, string $locale): array
    {
        if (! is_array($ctx) || $ctx === []) {
            return [];
        }

        $rows = [];
        foreach (['steps', 'heart_rate', 'sleep_duration'] as $type) {
            if (! isset($ctx[$type])) {
                continue;
            }
            $m = $ctx[$type];

            $rows[] = [
                'label' => __('anamnesis.health.'.$type),
                'value' => $this->formatMetricValue($type, $m, $locale),
                'norm' => $this->formatMetricNorm($type, $m, $locale),
                'avg7d' => $this->formatMetricAvg($type, $m, $locale),
                'status' => $m['status'] ?? null,
            ];
        }

        return $rows;
    }

    private function formatMetricValue(string $type, array $m, string $locale): string
    {
        $v = $m['value'] ?? null;
        if ($v === null) {
            return '—';
        }

        return match ($type) {
            'steps' => number_format((float) $v, 0, '.', ' ').' '.__('anamnesis.health.unit_steps'),
            'heart_rate' => round((float) $v).' '.__('anamnesis.health.unit_bpm'),
            'sleep_duration' => sprintf('%dh %02dm', intdiv((int) $v, 60), ((int) $v) % 60),
            default => (string) $v,
        };
    }

    private function formatMetricNorm(string $type, array $m, string $locale): string
    {
        $norm = $m['norm'] ?? null;
        if (! is_array($norm)) {
            return '—';
        }

        return match ($type) {
            'steps' => isset($norm['target'])
                ? number_format((float) $norm['target'], 0, '.', ' ')
                : '—',
            'heart_rate' => isset($norm['min'], $norm['max'])
                ? sprintf('%d–%d', (int) $norm['min'], (int) $norm['max'])
                : '—',
            'sleep_duration' => isset($norm['min'], $norm['max'])
                ? sprintf('%dh–%dh', intdiv((int) $norm['min'], 60), intdiv((int) $norm['max'], 60))
                : '—',
            default => '—',
        };
    }

    private function formatMetricAvg(string $type, array $m, string $locale): string
    {
        $a = $m['avg_7d'] ?? null;
        if ($a === null) {
            return '—';
        }
        $fake = ['value' => $a];

        return $this->formatMetricValue($type, $fake, $locale);
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

        $prompt = $this->buildAnamnesisPrompt($locale);

        try {
            $aiResponse = $this->ai->chat($prompt, $history, $chat->user, $locale);
            $answer = $aiResponse['answer'] ?? '';
            $parsed = $this->parseJson($answer);

            $fields = [];
            foreach (self::FIELDS as $field) {
                $fields[$field] = $parsed[$field] ?? null;
            }

            // Freeze the user's health snapshot at generation time so the
            // anamnesis remains a faithful clinical record even when the
            // user's metrics change afterwards.
            $healthSnapshot = app(HealthQueryService::class)->recentSnapshot($chat->user, 7);

            $anamnesis = Anamnesis::create(array_merge($fields, [
                'user_id' => Auth::id(),
                'chat_id' => $chat->id,
                'health_context' => $healthSnapshot ?: null,
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

    private function buildAnamnesisPrompt(?string $locale): string
    {
        $fields = implode(', ', self::FIELDS);
        $primary = strtolower(explode('-', (string) $locale)[0] ?: 'en');

        // English JSON keys stay constant — the parser depends on them.
        // Only the natural-language values + instructions vary by locale.
        // The example value steers the model toward the right script and
        // dialect; without it, a Russian chat can still produce English
        // output because the surrounding prompt is English.
        [$instruction, $exampleValue, $langLabel] = match ($primary) {
            'ru' => [
                'На основе нашего разговора составьте структурированный медицинский анамнез.',
                'головная боль 3 дня',
                'русском языке',
            ],
            'kk' => [
                'Біздің әңгімеміз негізінде құрылымдалған медициналық анамнез құрастырыңыз.',
                'бас ауруы 3 күн',
                'қазақ тілінде',
            ],
            default => [
                'Based on our conversation so far, produce a structured medical anamnesis.',
                'headache for 3 days',
                'English',
            ],
        };

        // Note on the example: previously used "..." as a placeholder for
        // the second populated field. The ai-service's content-safety
        // pre-filter has a flex-obfuscation pattern for "sex" that
        // matches three consecutive symbols ([*.\-_]) — three dots
        // included — and refused the whole prompt as "sensitive content".
        // Use a literal-text placeholder instead. Keep the value in the
        // example consistent with the chief_complaint so the model gets
        // a realistic shape.
        return $instruction.' '
            .'Output ONLY a valid JSON object (no prose, no markdown, no code fences) with exactly these keys: '
            .$fields.'. '
            ."Each value must be a concise string in {$langLabel} summarizing what the patient said in that category, or null if not mentioned. "
            .'Keep the JSON keys in English exactly as listed; only the values are localized. '
            .'Do not invent details. Example output: '
            .'{"chief_complaint":"'.$exampleValue.'","history_present_illness":"started yesterday","past_medical_history":null,'
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
