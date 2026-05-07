<?php

namespace App\Modules\AI\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Health\Services\HealthQueryService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIService
{
    public function __construct(private HealthQueryService $healthQuery) {}

    private function baseUrl(): string
    {
        return rtrim(env('AI_MODULE_URL', 'http://localhost:8000/v1/chat'), '/');
    }

    private function streamUrl(): string
    {
        $url = $this->baseUrl();

        return str_ends_with($url, '/v1/chat') ? $url.'/stream' : $url.'/v1/chat/stream';
    }

    private function headers(User $user): array
    {
        return [
            'X-Service-Token' => env('AI_SERVICE_TOKEN'),
            'X-User-Id' => (string) $user->id,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    private function normalizeLocale(?string $locale): string
    {
        if (!$locale) {
            return 'en';
        }
        $l = strtolower(substr(trim($locale), 0, 5));
        $l = str_replace('_', '-', $l);
        $primary = explode('-', $l)[0];
        $allowed = ['en', 'ru', 'kk'];

        return in_array($primary, $allowed, true) ? $primary : 'en';
    }

    private function buildPayload(string $message, ?array $history, User $user, string $locale, ?string $conversationId): array
    {
        $metrics = $this->healthQuery->recentSnapshot($user, 7);

        $payload = [
            'message' => $message,
            'locale' => $locale,
            'profile' => [
                'age' => $user->age ?? 25,
                'sex' => $user->sex ?? 'male',
                'goals' => ['chat_assistance'],
                'metrics' => $metrics === [] ? (object) [] : $metrics,
            ],
        ];

        // Only send `history` when we actually want to override the
        // server's Redis-stored history (regenerate/edit, or first turn).
        // For plain follow-ups the server reconstructs history itself,
        // saving prompt tokens.
        if ($history !== null) {
            $payload['history'] = $history;
        }

        if ($conversationId) {
            $payload['conversation_id'] = $conversationId;
        }

        return $payload;
    }

    public function chat(string $message, ?array $history, User $user, ?string $locale = null, ?string $conversationId = null): array
    {
        $locale = $this->normalizeLocale($locale);

        if (env('AI_USE_MOCK', false)) {
            return [
                'answer' => "(mock, locale={$locale}) I understand. Please continue describing your situation.",
                'conversation_id' => $conversationId ?: 'mock-'.bin2hex(random_bytes(8)),
                'mock' => true,
            ];
        }

        $payload = $this->buildPayload($message, $history, $user, $locale, $conversationId);

        Log::info('AIService::chat → POST', ['url' => $this->baseUrl(), 'user_id' => $user->id]);

        $start = microtime(true);

        $response = Http::connectTimeout(10)
            ->timeout(60)
            ->retry(2, 500, function ($exception, $request) {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                return false;
            }, throw: false)
            ->withHeaders($this->headers($user))
            ->post($this->baseUrl(), $payload);

        $elapsedMs = (int) ((microtime(true) - $start) * 1000);

        if ($response->failed()) {
            Log::error('AIService::chat failed', [
                'status' => $response->status(),
                'user_id' => $user->id,
                'elapsed_ms' => $elapsedMs,
            ]);
            throw new \RuntimeException('AI service request failed: HTTP '.$response->status());
        }

        Log::info('AIService::chat done', [
            'status' => $response->status(),
            'user_id' => $user->id,
            'elapsed_ms' => $elapsedMs,
        ]);

        $data = $response->json();

        if (!isset($data['answer'])) {
            $data['answer'] = $data['reply'] ?? $data['response'] ?? $data['message'] ?? '';
        }

        return $data;
    }

    /**
     * Stream a chat response from the ai-service SSE endpoint.
     * Yields associative arrays shaped like ['event' => string, 'data' => array|string].
     */
    public function streamChat(string $message, ?array $history, User $user, ?string $locale = null, ?string $conversationId = null): \Generator
    {
        $locale = $this->normalizeLocale($locale);

        if (env('AI_USE_MOCK', false)) {
            $cid = $conversationId ?: 'mock-'.bin2hex(random_bytes(8));
            yield ['event' => 'meta', 'data' => ['conversation_id' => $cid]];
            $full = "(mock stream, locale={$locale}) This is a streamed mock response arriving token by token.";
            foreach (explode(' ', $full) as $word) {
                yield ['event' => 'delta', 'data' => ['text' => $word.' ']];
                usleep(50000);
            }
            yield ['event' => 'final', 'data' => ['answer' => $full, 'conversation_id' => $cid]];

            return;
        }

        $payload = $this->buildPayload($message, $history, $user, $locale, $conversationId);
        $client = new Client(['connect_timeout' => 10, 'timeout' => 300, 'stream' => true]);

        Log::info('AIService::streamChat → POST', ['url' => $this->streamUrl(), 'user_id' => $user->id]);

        $attempts = 0;
        $maxAttempts = 2;
        $response = null;

        while ($attempts < $maxAttempts) {
            $attempts++;
            try {
                $response = $client->post($this->streamUrl(), [
                    'headers' => $this->headers($user),
                    'json' => $payload,
                    'stream' => true,
                ]);
                break;
            } catch (ConnectException $e) {
                if ($attempts >= $maxAttempts) {
                    Log::error('AIService::streamChat connect failed', [
                        'user_id' => $user->id,
                        'attempts' => $attempts,
                    ]);
                    yield ['event' => 'error', 'data' => ['message' => 'Could not reach AI service']];

                    return;
                }
                usleep(500_000);
            }
        }

        if ($response->getStatusCode() >= 400) {
            Log::error('AIService::streamChat failed', [
                'status' => $response->getStatusCode(),
                'user_id' => $user->id,
            ]);
            yield ['event' => 'error', 'data' => ['message' => 'Upstream error: '.$response->getStatusCode()]];

            return;
        }

        $body = $response->getBody();
        $buffer = '';
        $currentEvent = 'message';

        while (!$body->eof()) {
            $chunk = $body->read(1024);
            if ($chunk === '') {
                continue;
            }
            $buffer .= $chunk;

            while (($lineEnd = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $lineEnd);
                $buffer = substr($buffer, $lineEnd + 1);
                $line = rtrim($line, "\r");

                if ($line === '') {
                    continue;
                }

                if (str_starts_with($line, 'event:')) {
                    $currentEvent = trim(substr($line, 6));

                    continue;
                }

                if (str_starts_with($line, 'data:')) {
                    $raw = trim(substr($line, 5));
                    $decoded = json_decode($raw, true);
                    yield [
                        'event' => $currentEvent,
                        'data' => $decoded ?? $raw,
                    ];
                }
            }
        }
    }
}
