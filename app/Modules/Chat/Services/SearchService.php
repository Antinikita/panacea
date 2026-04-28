<?php

namespace App\Modules\Chat\Services;

use App\Modules\AI\Services\Embedder;
use Illuminate\Support\Facades\DB;

/**
 * Searches the calling user's own chat messages.
 *
 * Three modes:
 *   - text     keyword/full-text via tsvector @@ plainto_tsquery + ts_rank
 *              (Postgres) or LIKE fallback (sqlite, used in non-vector tests)
 *   - semantic cosine distance via embedding <=> queryEmbedding (Postgres only)
 *   - hybrid   reciprocal rank fusion of text + semantic (Postgres only)
 *
 * Always scoped to the chats owned by $userId so two users can't see each
 * other's history regardless of mode.
 */
class SearchService
{
    public function __construct(private Embedder $embedder) {}

    /**
     * @return array<int, array{
     *     message_id: int,
     *     chat_id: int,
     *     chat_title: ?string,
     *     role: string,
     *     snippet: string,
     *     rank: float,
     *     created_at: string,
     * }>
     */
    public function search(int $userId, string $query, string $mode = 'hybrid', int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $isPostgres = DB::getDriverName() === 'pgsql';

        return match ($mode) {
            'text' => $this->textSearch($userId, $query, $limit, $isPostgres),
            'semantic' => $isPostgres ? $this->semanticSearch($userId, $query, $limit) : [],
            'hybrid' => $isPostgres ? $this->hybridSearch($userId, $query, $limit) : $this->textSearch($userId, $query, $limit, false),
            default => [],
        };
    }

    private function textSearch(int $userId, string $query, int $limit, bool $isPostgres): array
    {
        if ($isPostgres) {
            $rows = DB::select(
                "SELECT cm.id, cm.chat_id, c.title AS chat_title, cm.role,
                        cm.message AS snippet, cm.created_at,
                        ts_rank(cm.tsv, plainto_tsquery('simple', :q)) AS rank
                 FROM chat_messages cm
                 JOIN chats c ON c.id = cm.chat_id
                 WHERE c.user_id = :user_id
                   AND cm.tsv @@ plainto_tsquery('simple', :q2)
                 ORDER BY rank DESC
                 LIMIT :lim",
                ['q' => $query, 'q2' => $query, 'user_id' => $userId, 'lim' => $limit]
            );
        } else {
            // sqlite fallback for tests / non-Postgres dev. Plain LIKE,
            // no ranking — this branch is intentionally crude.
            $rows = DB::select(
                'SELECT cm.id, cm.chat_id, c.title AS chat_title, cm.role,
                        cm.message AS snippet, cm.created_at,
                        1.0 AS rank
                 FROM chat_messages cm
                 JOIN chats c ON c.id = cm.chat_id
                 WHERE c.user_id = ?
                   AND cm.message LIKE ?
                 ORDER BY cm.created_at DESC
                 LIMIT ?',
                [$userId, '%'.$query.'%', $limit]
            );
        }

        return $this->shape($rows);
    }

    private function semanticSearch(int $userId, string $query, int $limit): array
    {
        $vector = $this->embedder->embed($query);
        $vectorLiteral = '['.implode(',', $vector).']';

        $rows = DB::select(
            'SELECT cm.id, cm.chat_id, c.title AS chat_title, cm.role,
                    cm.message AS snippet, cm.created_at,
                    1 - (cm.embedding <=> ?::vector) AS rank
             FROM chat_messages cm
             JOIN chats c ON c.id = cm.chat_id
             WHERE c.user_id = ?
               AND cm.embedding IS NOT NULL
             ORDER BY cm.embedding <=> ?::vector
             LIMIT ?',
            [$vectorLiteral, $userId, $vectorLiteral, $limit]
        );

        return $this->shape($rows);
    }

    /**
     * Hybrid uses reciprocal rank fusion: each document scored by 1/(k+rank)
     * across both ranked lists, summed. k=60 is the canonical RRF constant.
     */
    private function hybridSearch(int $userId, string $query, int $limit): array
    {
        $textHits = $this->textSearch($userId, $query, $limit * 2, true);
        $semanticHits = $this->semanticSearch($userId, $query, $limit * 2);

        $k = 60;
        $fused = [];

        foreach ($textHits as $i => $hit) {
            $fused[$hit['message_id']] = [
                'hit' => $hit,
                'score' => 1 / ($k + $i + 1),
            ];
        }

        foreach ($semanticHits as $i => $hit) {
            if (isset($fused[$hit['message_id']])) {
                $fused[$hit['message_id']]['score'] += 1 / ($k + $i + 1);
            } else {
                $fused[$hit['message_id']] = [
                    'hit' => $hit,
                    'score' => 1 / ($k + $i + 1),
                ];
            }
        }

        uasort($fused, fn ($a, $b) => $b['score'] <=> $a['score']);

        return collect($fused)
            ->take($limit)
            ->map(fn ($entry) => array_merge($entry['hit'], ['rank' => $entry['score']]))
            ->values()
            ->all();
    }

    private function shape(array $rows): array
    {
        return array_map(fn ($r) => [
            'message_id' => (int) $r->id,
            'chat_id' => (int) $r->chat_id,
            'chat_title' => $r->chat_title,
            'role' => $r->role,
            'snippet' => $r->snippet,
            'rank' => (float) $r->rank,
            'created_at' => $r->created_at,
        ], $rows);
    }
}
