<?php

namespace App\Services;

use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIService
{
    private function baseUrl(): string
    {
        return rtrim(env('AI_MODULE_URL', 'http://localhost:8000/v1/chat'), '/');
    }

    private function streamUrl(): string
    {
        $url = $this->baseUrl();
        return str_ends_with($url, '/v1/chat') ? $url . '/stream' : $url . '/stream';
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
        if (!$locale) return 'en';
        $l = strtolower(substr(trim($locale), 0, 5));
        $l = str_replace('_', '-', $l);
        $primary = explode('-', $l)[0];
        $allowed = ['en', 'ru', 'kk'];
        return in_array($primary, $allowed, true) ? $primary : 'en';
    }

    private function buildPayload(string $message, array $history, User $user, string $locale): array
    {
        return [
            'message' => $message,
            'locale' => $locale,
            'history' => $history,
            'profile' => [
                'age' => $user->age ?? 25,
                'sex' => $user->sex ?? 'male',
                'goals' => ['chat_assistance'],
                'metrics' => (object) [],
            ],
        ];
    }

    public function chat(string $message, array $history, User $user, ?string $locale = null): array
    {
        $locale = $this->normalizeLocale($locale);

        if (env('AI_USE_MOCK', false)) {
            return [
                'answer' => "(mock, locale={$locale}) I understand. Please continue describing your situation.",
                'mock' => true,
            ];
        }

        $payload = $this->buildPayload($message, $history, $user, $locale);

        Log::info('AIService::chat → POST', ['url' => $this->baseUrl(), 'user_id' => $user->id]);

        $response = Http::timeout(300)
            ->withHeaders($this->headers($user))
            ->post($this->baseUrl(), $payload);

        if ($response->failed()) {
            Log::error('AIService::chat failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('AI service request failed: HTTP ' . $response->status());
        }

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
    public function streamChat(string $message, array $history, User $user, ?string $locale = null): \Generator
    {
        $locale = $this->normalizeLocale($locale);

        if (env('AI_USE_MOCK', false)) {
            $full = "(mock stream, locale={$locale}) This is a streamed mock response arriving token by token.";
            foreach (explode(' ', $full) as $word) {
                yield ['event' => 'delta', 'data' => ['text' => $word . ' ']];
                usleep(50000);
            }
            yield ['event' => 'final', 'data' => ['answer' => $full]];
            return;
        }

        $payload = $this->buildPayload($message, $history, $user, $locale);
        $client = new Client(['timeout' => 300, 'stream' => true]);

        Log::info('AIService::streamChat → POST', ['url' => $this->streamUrl(), 'user_id' => $user->id]);

        $response = $client->post($this->streamUrl(), [
            'headers' => $this->headers($user),
            'json' => $payload,
            'stream' => true,
        ]);

        if ($response->getStatusCode() >= 400) {
            $body = (string) $response->getBody();
            Log::error('AIService::streamChat failed', ['status' => $response->getStatusCode(), 'body' => $body]);
            yield ['event' => 'error', 'data' => ['message' => 'Upstream error: ' . $response->getStatusCode()]];
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
