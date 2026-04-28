<?php

namespace App\Modules\AI\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Wraps the ai-service /v1/embed endpoint.
 *
 * The ai-service contract for embeddings is owned by the Python team;
 * this client posts {text, locale} and expects {embedding: [float, ...]}.
 * The vector dimension matches OpenAI text-embedding-3-small (1536),
 * which is what the chat_messages.embedding column is sized for.
 *
 * AI_USE_MOCK=true returns a deterministic pseudo-embedding so the
 * pipeline (events -> job -> stored vector) can be exercised end-to-end
 * in tests and offline dev without burning OpenAI tokens.
 */
class Embedder
{
    public const DIMENSIONS = 1536;

    private function url(): string
    {
        $base = rtrim(env('AI_MODULE_URL', 'http://localhost:8000/v1/chat'), '/');

        // baseUrl is /v1/chat; the embed endpoint sits next to it under /v1.
        if (str_ends_with($base, '/v1/chat')) {
            return substr($base, 0, -strlen('/v1/chat')).'/v1/embed';
        }

        return $base.'/embed';
    }

    public function embed(string $text, ?string $locale = null): array
    {
        if (env('AI_USE_MOCK', false)) {
            return $this->mockEmbedding($text);
        }

        $start = microtime(true);

        try {
            $response = Http::connectTimeout(10)
                ->timeout(30)
                ->withHeaders([
                    'X-Service-Token' => env('AI_SERVICE_TOKEN'),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($this->url(), [
                    'text' => $text,
                    'locale' => $locale ?? 'en',
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Embedder unreachable; falling back to mock', [
                'elapsed_ms' => (int) ((microtime(true) - $start) * 1000),
            ]);

            return $this->mockEmbedding($text);
        }

        if ($response->failed()) {
            Log::error('Embedder failed', [
                'status' => $response->status(),
                'elapsed_ms' => (int) ((microtime(true) - $start) * 1000),
            ]);
            throw new \RuntimeException('Embed service request failed: HTTP '.$response->status());
        }

        $vector = $response->json('embedding');

        if (!is_array($vector) || count($vector) !== self::DIMENSIONS) {
            throw new \RuntimeException('Embed service returned malformed vector');
        }

        Log::info('Embedder done', [
            'elapsed_ms' => (int) ((microtime(true) - $start) * 1000),
        ]);

        return $vector;
    }

    /**
     * Deterministic stand-in for the ai-service. Used in tests + when
     * AI_USE_MOCK is enabled. Same input -> same output, so semantic
     * search assertions are stable.
     */
    private function mockEmbedding(string $text): array
    {
        $hash = hash('sha256', $text, true);
        $vector = [];

        for ($i = 0; $i < self::DIMENSIONS; $i++) {
            $byte = ord($hash[$i % strlen($hash)]);
            $vector[] = (($byte / 255.0) - 0.5) * 0.1;
        }

        return $vector;
    }
}
